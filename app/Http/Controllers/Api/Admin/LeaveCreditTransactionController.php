<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveCreditTransaction;
use App\Models\LeavePolicy;
use Carbon\Carbon;
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
            ->with([
                'employee:id,department_id,emp_id,firstname,lastname',
                'employee.department:id,department_name,department_code',
                'leavePolicy.leaveType',
                'leaveRequest:id,request_no',
                'createdBy',
            ])
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
            'department_id' => 'nullable|integer|exists:departments,id',
        ]);

        $today = Carbon::today();
        $cycleStart = $today->copy()->startOfYear()->toDateString();
        $cycleEnd = $today->copy()->endOfYear()->toDateString();

        $employees = Employee::query()
            ->with([
                'department:id,department_name,department_code',
                'employments:id,employee_id,employment_type_id,date_hired',
                'employments.employmentType:id,name',
            ])
            ->when($filters['employee_id'] ?? null, fn ($query, $id) => $query->whereKey($id))
            ->when($filters['department_id'] ?? null, fn ($query, $id) => $query->where('department_id', $id))
            ->orderBy('id')
            ->get();

        $employmentTypeIds = $employees
            ->flatMap(fn (Employee $employee) => $employee->employments->pluck('employment_type_id'))
            ->unique()
            ->values();

        $policies = LeavePolicy::query()
            ->with('leaveType:id,leave_code,leave_name,status')
            ->whereIn('employment_type_id', $employmentTypeIds)
            ->where('status', 'approved')
            ->where('is_eligible', true)
            ->where('is_paid', true)
            ->whereHas('leaveType', fn ($query) => $query->where('status', 'active'))
            ->where(function ($query) use ($today): void {
                $query->whereNull('effective_from')
                    ->orWhereDate('effective_from', '<=', $today);
            })
            ->where(function ($query) use ($today): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $today);
            })
            ->get();

        $balances = collect();

        foreach ($employees as $employee) {
            $employment = $employee->employments
                ->sortByDesc(fn ($item): string => ($item->date_hired?->format('Ymd') ?? '').str_pad((string) $item->id, 10, '0', STR_PAD_LEFT))
                ->first();

            if (! $employment) {
                continue;
            }

            $monthsOfService = max(0, (int) Carbon::parse($employment->date_hired)->diffInMonths($today));
            $qualifiedPolicies = $policies
                ->where('employment_type_id', $employment->employment_type_id)
                ->groupBy('leave_type_id')
                ->map(fn ($group) => $group
                    ->filter(fn (LeavePolicy $policy): bool => (int) $policy->min_service_months <= $monthsOfService)
                    ->sortByDesc('min_service_months')
                    ->first())
                ->filter()
                ->sortBy(fn (LeavePolicy $policy) => $policy->leaveType?->leave_name);

            foreach ($qualifiedPolicies as $policy) {
                $ledger = DB::table('leave_credit_transactions as transactions')
                    ->join('leave_policies as policies', 'policies.id', '=', 'transactions.leave_policy_id')
                    ->where('transactions.employee_id', $employee->id)
                    ->where('policies.leave_type_id', $policy->leave_type_id)
                    ->where('policies.employment_type_id', $employment->employment_type_id)
                    ->where('policies.is_paid', true)
                    ->where('transactions.cycle_start', $cycleStart)
                    ->where('transactions.cycle_end', $cycleEnd)
                    ->selectRaw('COUNT(transactions.id) as transaction_count')
                    ->selectRaw("SUM(CASE WHEN transactions.transaction_type = 'allocation' THEN transactions.days_delta ELSE 0 END) as ledger_allocated")
                    ->selectRaw("SUM(CASE WHEN transactions.transaction_type = 'deduction' THEN ABS(transactions.days_delta) ELSE 0 END) as gross_deductions")
                    ->selectRaw("SUM(CASE WHEN transactions.transaction_type = 'reversal' OR (transactions.transaction_type = 'adjustment' AND transactions.reference_key LIKE 'LEAVE-UNDO-%') THEN transactions.days_delta ELSE 0 END) as restored_days")
                    ->selectRaw("SUM(CASE WHEN transactions.transaction_type = 'adjustment' THEN transactions.days_delta ELSE 0 END) as adjustments")
                    ->selectRaw("SUM(CASE WHEN transactions.transaction_type = 'carry_over' THEN transactions.days_delta ELSE 0 END) as carry_over")
                    ->selectRaw("SUM(CASE WHEN transactions.transaction_type = 'expiration' THEN transactions.days_delta ELSE 0 END) as expired")
                    ->selectRaw("SUM(CASE WHEN transactions.transaction_type = 'reversal' THEN transactions.days_delta ELSE 0 END) as reversals")
                    ->selectRaw('COALESCE(SUM(transactions.days_delta), 0) as available')
                    ->first();

                $hasTransactions = (int) ($ledger->transaction_count ?? 0) > 0;
                $allocated = round((float) $policy->annual_credits, 2);
                $grossDeductions = round((float) ($ledger->gross_deductions ?? 0), 2);
                $restoredDays = round((float) ($ledger->restored_days ?? 0), 2);
                $used = max(0, round($grossDeductions - $restoredDays, 2));
                $department = $employee->department;

                $balances->push([
                    'employee_id' => $employee->id,
                    'employee' => trim($employee->firstname.' '.$employee->lastname),
                    'emp_id' => $employee->emp_id,
                    'department_id' => $department?->id,
                    'department' => $department?->department_name,
                    'department_name' => $department?->department_name,
                    'department_code' => $department?->department_code,
                    'department_details' => $department ? [
                        'id' => $department->id,
                        'department_name' => $department->department_name,
                        'department_code' => $department->department_code,
                    ] : null,
                    'employment_type_id' => $employment->employment_type_id,
                    'employment_type' => $employment->employmentType?->name,
                    'leave_type_id' => $policy->leave_type_id,
                    'leave_code' => $policy->leaveType?->leave_code,
                    'leave_policy_id' => $policy->id,
                    'leave_type' => $policy->leaveType?->leave_name,
                    'cycle_start' => $cycleStart,
                    'cycle_end' => $cycleEnd,
                    'allocated' => $allocated,
                    'ledger_allocated' => round((float) ($ledger->ledger_allocated ?? 0), 2),
                    'used' => $used,
                    'deductions' => $used,
                    'gross_deductions' => $grossDeductions,
                    'restored_days' => $restoredDays,
                    'adjustments' => round((float) ($ledger->adjustments ?? 0), 2),
                    'carry_over' => round((float) ($ledger->carry_over ?? 0), 2),
                    'expired' => round((float) ($ledger->expired ?? 0), 2),
                    'reversals' => round((float) ($ledger->reversals ?? 0), 2),
                    'available' => $hasTransactions
                        ? round((float) $ledger->available, 2)
                        : $allocated,
                    'has_ledger_transactions' => $hasTransactions,
                ]);
            }
        }

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

            if (! $policy) {
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

            if (! $allocation) {
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
                'reference_key' => 'ADJ-'.Str::uuid(),
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
