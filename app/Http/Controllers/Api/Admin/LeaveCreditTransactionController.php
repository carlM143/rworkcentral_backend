<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveCreditTransaction;
use App\Models\LeavePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LeaveCreditTransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'employee_id' => 'nullable|integer|exists:employees,id',
            'leave_policy_id' => 'nullable|integer|exists:leave_policies,id',
            'transaction_type' => 'nullable|in:allocation,deduction,adjustment,carry_over,expiration,reversal',
        ]);

        $transactions = LeaveCreditTransaction::query()
            ->with(['employee:id,emp_id,firstname,lastname', 'leavePolicy.leaveType', 'leaveRequest:id,request_no', 'createdBy'])
            ->when($filters['employee_id'] ?? null, fn ($q, $id) => $q->where('employee_id', $id))
            ->when($filters['leave_policy_id'] ?? null, fn ($q, $id) => $q->where('leave_policy_id', $id))
            ->when($filters['transaction_type'] ?? null, fn ($q, $type) => $q->where('transaction_type', $type))
            ->latest('id')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'message' => 'Leave credit transactions retrieved successfully.',
            'data' => $transactions,
        ]);
    }

    public function balances(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'employee_id' => 'nullable|integer|exists:employees,id',
        ]);

        $today = now()->toDateString();

        $balances = LeaveCreditTransaction::query()
            ->with(['employee:id,emp_id,firstname,lastname', 'leavePolicy.leaveType'])
            ->select('employee_id', 'leave_policy_id', 'cycle_start', 'cycle_end')
            ->selectRaw("SUM(CASE WHEN transaction_type = 'allocation' THEN days_delta ELSE 0 END) as allocated")
            ->selectRaw("SUM(CASE WHEN transaction_type = 'deduction' THEN days_delta ELSE 0 END) as deductions")
            ->selectRaw("SUM(CASE WHEN transaction_type = 'adjustment' THEN days_delta ELSE 0 END) as adjustments")
            ->selectRaw("SUM(CASE WHEN transaction_type = 'carry_over' THEN days_delta ELSE 0 END) as carry_over")
            ->selectRaw("SUM(CASE WHEN transaction_type = 'expiration' THEN days_delta ELSE 0 END) as expired")
            ->selectRaw("SUM(CASE WHEN transaction_type = 'reversal' THEN days_delta ELSE 0 END) as reversals")
            ->selectRaw('SUM(days_delta) as available')
            ->whereDate('cycle_start', '<=', $today)
            ->whereDate('cycle_end', '>=', $today)
            ->when($filters['employee_id'] ?? null, fn ($q, $id) => $q->where('employee_id', $id))
            ->groupBy('employee_id', 'leave_policy_id', 'cycle_start', 'cycle_end')
            ->orderBy('employee_id')
            ->get()
            ->map(fn ($item) => [
                'employee_id' => $item->employee_id,
                'employee' => trim(($item->employee?->firstname ?? '') . ' ' . ($item->employee?->lastname ?? '')),
                'emp_id' => $item->employee?->emp_id,
                'leave_policy_id' => $item->leave_policy_id,
                'leave_type' => $item->leavePolicy?->leaveType?->leave_name,
                'cycle_start' => $item->cycle_start?->format('Y-m-d'),
                'cycle_end' => $item->cycle_end?->format('Y-m-d'),
                'allocated' => (float) $item->allocated,
                'deductions' => (float) $item->deductions,
                'adjustments' => (float) $item->adjustments,
                'carry_over' => (float) $item->carry_over,
                'expired' => (float) $item->expired,
                'reversals' => (float) $item->reversals,
                'available' => (float) $item->available,
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Employee leave balances retrieved successfully.',
            'data' => $balances,
        ]);
    }

    public function adjust(Request $request): JsonResponse
    {
        Gate::authorize('adjust-leave-credits');

        $validated = $request->validate([
            'employee_id' => 'required|integer|exists:employees,id',
            'leave_policy_id' => 'required|integer|exists:leave_policies,id',
            'action' => 'required|in:add,deduct',
            'days' => 'required|numeric|gt:0|decimal:0,2|max:999999.99',
            'remarks' => 'required|string|min:5|max:1000',
        ]);

        $transaction = DB::transaction(function () use ($request, $validated) {
            $today = now()->toDateString();

            $employee = Employee::query()
                ->lockForUpdate()
                ->findOrFail($validated['employee_id']);

            $policy = LeavePolicy::query()
                ->whereKey($validated['leave_policy_id'])
                ->where('status', 'approved')
                ->where('is_eligible', true)
                ->where('is_paid', true)
                ->whereDate('effective_from', '<=', $today)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))
                ->first();

            if (!$policy) {
                throw ValidationException::withMessages([
                    'leave_policy_id' => 'An active, approved and eligible paid leave policy is required.',
                ]);
            }

            $employmentTypeId = $employee->employments()
                ->latest('id')
                ->value('employment_type_id');

            if ((int) $employmentTypeId !== (int) $policy->employment_type_id) {
                throw ValidationException::withMessages([
                    'leave_policy_id' => 'The leave policy does not match the employee current employment type.',
                ]);
            }

            $allocation = LeaveCreditTransaction::query()
                ->where('employee_id', $employee->id)
                ->where('leave_policy_id', $policy->id)
                ->where('transaction_type', 'allocation')
                ->whereDate('cycle_start', '<=', $today)
                ->whereDate('cycle_end', '>=', $today)
                ->latest('id')
                ->first();

            if (!$allocation) {
                throw ValidationException::withMessages([
                    'leave_policy_id' => 'No allocated credits found for the current cycle. Allocate eligible credits first.',
                ]);
            }

            $cycleStart = $allocation->cycle_start->format('Y-m-d');
            $cycleEnd = $allocation->cycle_end->format('Y-m-d');

            $available = LeaveCreditTransaction::query()
                ->where('employee_id', $employee->id)
                ->where('leave_policy_id', $policy->id)
                ->whereDate('cycle_start', $cycleStart)
                ->whereDate('cycle_end', $cycleEnd)
                ->sum('days_delta');

            $days = (float) $validated['days'];
            $delta = $validated['action'] === 'add' ? $days : -$days;

            if (round((float) $available + $delta, 2) < 0) {
                throw ValidationException::withMessages([
                    'days' => 'Insufficient leave credits for this adjustment.',
                ]);
            }

            return LeaveCreditTransaction::create([
                'employee_id' => $employee->id,
                'leave_policy_id' => $policy->id,
                'leave_request_id' => null,
                'transaction_type' => 'adjustment',
                'days_delta' => number_format($delta, 2, '.', ''),
                'cycle_start' => $cycleStart,
                'cycle_end' => $cycleEnd,
                'effective_date' => $today,
                'reference_key' => 'ADJ-' . Str::uuid(),
                'remarks' => $validated['remarks'],
                'created_by' => $request->user()->id,
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Leave credit adjustment saved successfully.',
            'data' => $transaction->load(['employee', 'leavePolicy.leaveType', 'createdBy']),
        ], 201);
    }
}
