<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\LeaveCreditService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveApprovalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeApproval($request);

        $requests = LeaveRequest::query()
            
            ->with([
                'employee:id,emp_id,department_id,firstname,lastname',

                // Employee Department
                'employee.department:id,department_name',

                // Employee Position
                'employee.latestEmployment.position:id,position',

                // Leave Information
                'leaveType:id,leave_code,leave_name',
                'leavePolicy:id,leave_type_id,employment_type_id,is_eligible,is_paid,status',
                'leaveDates',
                'approver:id,name,email',
            ])
            ->latest('id')
            ->get()
            ->map(fn (LeaveRequest $leaveRequest): array => $this->formatRequest($leaveRequest));

        return response()->json([
            'success' => true,
            'message' => 'Leave requests retrieved successfully.',
            'data' => $requests,
        ]);
    }

    public function approve(
        Request $request,
        LeaveRequest $leaveRequest,
        LeaveCreditService $credits
    ): JsonResponse {
        $this->authorizeApproval($request);

        $approved = DB::transaction(function () use (
            $request,
            $leaveRequest,
            $credits
        ): LeaveRequest {
            $record = LeaveRequest::query()
                ->lockForUpdate()
                ->findOrFail($leaveRequest->id);

            if ($record->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => 'Only pending leave requests can be approved.',
                ]);
            }

            $employee = Employee::query()
                ->whereKey($record->employee_id)
                ->lockForUpdate()
                ->firstOrFail();
            $dates = DB::table('leave_request_dates')
                ->where('leave_request_id', $record->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($dates->isEmpty()) {
                throw ValidationException::withMessages([
                    'dates' => 'No requested leave dates were found.',
                ]);
            }

            $classifiedDates = $this->classifyDates($record, $dates);
            $firstDate = (string) $classifiedDates->min('leave_date');
            $lastDate = (string) $classifiedDates->max('leave_date');
            $year = Carbon::parse($firstDate)->year;

            if (Carbon::parse($lastDate)->year !== $year) {
                throw ValidationException::withMessages([
                    'dates' => 'Leave dates spanning different years require separate requests.',
                ]);
            }

            $overlapping = DB::table('leave_request_dates as dates')
                ->join('leave_requests as requests', 'requests.id', '=', 'dates.leave_request_id')
                ->where('requests.employee_id', $employee->id)
                ->where('requests.id', '!=', $record->id)
                ->where('requests.status', 'approved')
                ->whereIn('dates.leave_date', $classifiedDates->pluck('leave_date'))
                ->exists();

            if ($overlapping) {
                throw ValidationException::withMessages([
                    'dates' => 'One or more leave dates already have an approved leave request.',
                ]);
            }

            $paidDays = round((float) $classifiedDates
                ->where('requested_pay_type', 'paid')
                ->sum('day_value'), 2);
            $unpaidDays = round((float) $classifiedDates
                ->where('requested_pay_type', 'unpaid')
                ->sum('day_value'), 2);
            $approvedPayType = $paidDays > 0 && $unpaidDays > 0
                ? 'mixed'
                : ($paidDays > 0 ? 'paid' : 'unpaid');

            if ($record->requested_pay_type !== $approvedPayType) {
                throw ValidationException::withMessages([
                    'requested_pay_type' => 'The parent payment type does not match its individual leave dates.',
                ]);
            }

            $policy = null;
            if ($paidDays > 0) {
                $policy = $credits->resolvePaidPolicy(
                    $employee,
                    $record->leave_type_id,
                    $firstDate,
                    $lastDate
                );

                if (! $policy) {
                    throw ValidationException::withMessages([
                        'leave_policy_id' => 'No eligible paid leave policy matches this employment type and service period.',
                    ]);
                }

                if ($policy->usage_window !== 'calendar_year' || $policy->validity_period !== 'annual') {
                    throw ValidationException::withMessages([
                        'leave_policy_id' => 'This paid policy requires a different credit cycle calculation.',
                    ]);
                }

                $credits->syncAnnualCredits(
                    $employee,
                    $policy,
                    $year,
                    (int) $request->user()->id
                );
                $available = $credits->availableBalance($employee, $policy, $year);

                if ($paidDays > $available) {
                    throw ValidationException::withMessages([
                        'leave_credits' => "Insufficient paid leave credits for {$year}. Available: {$available}, Requested: {$paidDays}.",
                    ]);
                }

                $credits->deductApprovedLeave(
                    $employee,
                    $policy,
                    $record,
                    $paidDays,
                    $year,
                    $firstDate,
                    (int) $request->user()->id
                );
            }

            foreach ($classifiedDates as $date) {
                DB::table('leave_request_dates')
                    ->where('id', $date['id'])
                    ->update([
                        'requested_pay_type' => $date['requested_pay_type'],
                        'approved_pay_type' => $date['requested_pay_type'],
                        'updated_at' => now(),
                    ]);
            }

            $record->update([
                'leave_policy_id' => $policy?->id,
                'approved_pay_type' => $approvedPayType,
                'status' => 'approved',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);

            return $record->fresh([
                'employee:id,emp_id,department_id,firstname,lastname',
                'employee.department:id,department_name',
                'employee.latestEmployment.position:id,position',
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Leave request approved successfully.',
            'data' => $this->formatRequest($approved),
        ]);
    }

    /**
     * Undo an approved/rejected leave decision.
     * For approved paid or mixed leave, the original credit deduction is
     * offset by an auditable positive ledger entry BEFORE changing status.
     */
    public function revert(
        Request $request,
        LeaveRequest $leaveRequest,
        LeaveCreditService $credits
    ): JsonResponse {
        $this->authorizeApproval($request);

        $result = DB::transaction(function () use ($request, $leaveRequest, $credits): array {
            $record = LeaveRequest::query()
                ->whereKey($leaveRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($record->status, ['approved', 'rejected'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Only approved or rejected leave requests can be reverted to pending.',
                ]);
            }

            $employee = Employee::query()
                ->whereKey($record->employee_id)
                ->lockForUpdate()
                ->firstOrFail();

            $restoredDays = 0.0;
            if ($record->status === 'approved') {
                $restoredDays = $credits->restoreApprovedLeave(
                    $employee,
                    $record,
                    (int) $request->user()->id
                );
            }

            DB::table('leave_request_dates')
                ->where('leave_request_id', $record->id)
                ->update([
                    'approved_pay_type' => null,
                    'updated_at' => now(),
                ]);

            $record->update([
                'status' => 'pending',
                'approved_pay_type' => null,
                'approved_by' => null,
                'approved_at' => null,
                // Keep leave_policy_id so the pending request retains its
                // policy association for review; approval re-resolves it.
            ]);

            return [
                'restored_days' => $restoredDays,
                'request' => $record->fresh([
                    'employee:id,emp_id,department_id,firstname,lastname',
                    'employee.department:id,department_name',
                    'employee.latestEmployment.position:id,position',
                    'leaveType:id,leave_code,leave_name',
                    'leavePolicy:id,leave_type_id,employment_type_id,is_eligible,is_paid,status',
                    'leaveDates',
                    'approver:id,name,email',
                ]),
            ];
        });

        return response()->json([
            'success' => true,
            'message' => $result['restored_days'] > 0
                ? "Leave request reverted to pending. {$result['restored_days']} paid leave day(s) restored."
                : 'Leave request reverted to pending. No paid leave credits required restoration.',
            'restored_days' => $result['restored_days'],
            'data' => $this->formatRequest($result['request']),
        ]);
    }

    private function classifyDates(LeaveRequest $request, Collection $dates): Collection
    {
        if ($dates->pluck('leave_date')->unique()->count() !== $dates->count()) {
            throw ValidationException::withMessages([
                'dates' => 'Duplicate requested leave dates were found.',
            ]);
        }

        return $dates->map(function ($date) use ($request): array {
            $value = (float) $date->day_value;
            $expectedValue = $date->day_type === 'half_day' ? 0.5 : 1.0;

            if (! in_array($date->day_type, ['whole_day', 'half_day'], true)
                || $value !== $expectedValue) {
                throw ValidationException::withMessages([
                    'dates' => 'Each date must contain a valid whole-day or half-day value.',
                ]);
            }

            $payType = $date->requested_pay_type;
            if (! $payType && in_array($request->requested_pay_type, ['paid', 'unpaid'], true)) {
                $payType = $request->requested_pay_type;
            }

            if (! in_array($payType, ['paid', 'unpaid'], true)) {
                throw ValidationException::withMessages([
                    'dates' => 'Every leave date must be classified as paid or unpaid.',
                ]);
            }

            return [
                'id' => $date->id,
                'leave_date' => Carbon::parse($date->leave_date)->toDateString(),
                'day_type' => $date->day_type,
                'day_value' => $value,
                'requested_pay_type' => $payType,
            ];
        })->sortBy('leave_date')->values();
    }

    private function formatRequest(LeaveRequest $request): array
    {
        $dates = $request->leaveDates->sortBy('leave_date')->values();

        return [
            'id' => $request->id,
            'request_no' => $request->request_no,
            
            
            'employee' => [
                'id' => $request->employee?->id,

                'emp_id' => $request->employee?->emp_id,

                'name' => trim(
                    ($request->employee?->firstname ?? '') . ' ' .
                    ($request->employee?->lastname ?? '')
                ),

                'department' => $request->employee
                    ?->department
                    ?->department_name,

                'position' => $request->employee
                    ?->latestEmployment
                    ?->position
                    ?->position,
            ],


            'leave_type' => $request->leaveType?->leave_name ?? 'Unknown Leave Type',
            'leave_code' => $request->leaveType?->leave_code,
            'leave_policy_id' => $request->leave_policy_id,
            'policy_status' => $request->leavePolicy?->status,
            'policy_eligible' => $request->leavePolicy ? (bool) $request->leavePolicy->is_eligible : null,
            'policy_paid' => $request->leavePolicy ? (bool) $request->leavePolicy->is_paid : null,
            'request_date' => $request->request_date?->format('Y-m-d'),
            'requested_pay_type' => $request->requested_pay_type,
            'approved_pay_type' => $request->approved_pay_type,
            'dates' => $dates->map(fn ($date) => [
                'id' => $date->id,
                'leave_date' => $date->leave_date?->format('Y-m-d'),
                'day_type' => $date->day_type,
                'day_value' => (float) $date->day_value,
                'requested_pay_type' => $date->requested_pay_type
                    ?? (in_array($request->requested_pay_type, ['paid', 'unpaid'], true)
                        ? $request->requested_pay_type
                        : null),
                'approved_pay_type' => $date->approved_pay_type,
            ])->all(),
            'total_requested_days' => round((float) $dates->sum('day_value'), 2),
            'total_requested_paid_days' => round((float) $dates
                ->filter(fn ($date) => ($date->requested_pay_type ?? $request->requested_pay_type) === 'paid')
                ->sum('day_value'), 2),
            'total_requested_unpaid_days' => round((float) $dates
                ->filter(fn ($date) => ($date->requested_pay_type ?? $request->requested_pay_type) === 'unpaid')
                ->sum('day_value'), 2),
            'reason' => $request->reason,
            'status' => $request->status,
            'approved_by' => $request->approved_by,
            'approver' => $request->approver,
            'approved_at' => $request->approved_at?->toIso8601String(),
            'created_at' => $request->created_at?->toIso8601String(),
        ];
    }

    private function authorizeApproval(Request $request): void
    {
        abort_unless(
            in_array($request->user()?->role?->name, ['Administrator', 'Human Resources'], true),
            403,
            'You are not authorized to approve leave requests.'
        );
    }
}
