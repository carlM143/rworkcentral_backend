<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\LeaveCreditService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

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

    public function preview(Request $request, LeaveCreditService $credits): JsonResponse
    {
        $this->authorizeApproval($request);
        $validated = $this->validateAdminFiling($request);
        $employee = Employee::query()->where('status', true)->findOrFail($validated['employee_id']);
        $dates = $this->normalizeAdminDates($employee, $validated, $credits);
        $payType = $this->deriveAdminPayType($dates, $validated['requested_pay_type']);
        $calendarDays = $this->selectedCalendarDateCount($validated);

        return response()->json([
            'success' => true,
            'message' => 'Employee leave schedule calculated successfully.',
            'data' => [
                'employee_id' => $employee->id,
                'requested_pay_type' => $payType,
                'calendar_days' => $calendarDays,
                'scheduled_working_days' => count($dates),
                'excluded_rest_days' => max(0, $calendarDays - count($dates)),
                'total_days' => round((float) collect($dates)->sum('day_value'), 2),
                'total_paid_days' => round((float) collect($dates)->where('requested_pay_type', 'paid')->sum('day_value'), 2),
                'total_unpaid_days' => round((float) collect($dates)->where('requested_pay_type', 'unpaid')->sum('day_value'), 2),
                'dates' => $dates,
            ],
        ]);
    }

    public function store(Request $request, LeaveCreditService $credits): JsonResponse
    {
        $this->authorizeApproval($request);
        $validated = $this->validateAdminFiling($request);
        $employee = Employee::query()->where('status', true)->findOrFail($validated['employee_id']);
        $leaveType = LeaveType::query()
            ->where('status', 'active')
            ->find($validated['leave_type_id']);

        if (! $leaveType) {
            throw ValidationException::withMessages([
                'leave_type_id' => 'Please select an active leave type.',
            ]);
        }

        $dates = $this->normalizeAdminDates($employee, $validated, $credits);
        $payType = $this->deriveAdminPayType($dates, $validated['requested_pay_type']);
        $filePath = $this->handleAdminFileUpload($request, $employee->id)
            ?? ($validated['file_path'] ?? null);

        try {
            $leaveRequest = DB::transaction(function () use (
                $request,
                $credits,
                $validated,
                $employee,
                $leaveType,
                $dates,
                $payType,
                $filePath
            ): LeaveRequest {
                $lockedEmployee = Employee::query()
                    ->whereKey($employee->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $dateValues = collect($dates)->pluck('leave_date');

                $overlap = DB::table('leave_request_dates as dates')
                    ->join('leave_requests as requests', 'requests.id', '=', 'dates.leave_request_id')
                    ->where('requests.employee_id', $lockedEmployee->id)
                    ->whereIn('requests.status', ['pending', 'approved'])
                    ->whereIn('dates.leave_date', $dateValues)
                    ->exists();

                if ($overlap) {
                    throw ValidationException::withMessages([
                        'dates' => 'One or more dates already have a pending or approved leave request for this employee.',
                    ]);
                }

                $firstDate = (string) $dateValues->min();
                $lastDate = (string) $dateValues->max();
                $year = Carbon::parse($firstDate)->year;
                $paidDays = round((float) collect($dates)
                    ->where('requested_pay_type', 'paid')
                    ->sum('day_value'), 2);
                $policy = null;

                if ($paidDays > 0) {
                    $policy = $credits->resolvePaidPolicy(
                        $lockedEmployee,
                        $leaveType->id,
                        $firstDate,
                        $lastDate
                    );

                    if (! $policy) {
                        throw ValidationException::withMessages([
                            'leave_policy_id' => 'No eligible paid leave policy matches this employee employment type and service period.',
                        ]);
                    }

                    if ($policy->usage_window !== 'calendar_year' || $policy->validity_period !== 'annual') {
                        throw ValidationException::withMessages([
                            'leave_policy_id' => 'This paid policy requires a different credit cycle calculation.',
                        ]);
                    }

                    $credits->syncAnnualCredits(
                        $lockedEmployee,
                        $policy,
                        $year,
                        (int) $request->user()->id
                    );
                    $available = $credits->availableBalance($lockedEmployee, $policy, $year);

                    if ($paidDays > $available) {
                        throw ValidationException::withMessages([
                            'leave_credits' => "Insufficient paid leave credits for {$year}. Available: {$available}, Requested: {$paidDays}.",
                        ]);
                    }
                }

                $record = LeaveRequest::create([
                    'employee_id' => $lockedEmployee->id,
                    'leave_type_id' => $leaveType->id,
                    'leave_policy_id' => $policy?->id,
                    'request_no' => 'LR-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
                    'request_date' => $validated['request_date'] ?? now()->toDateString(),
                    'requested_pay_type' => $payType,
                    'approved_pay_type' => $payType,
                    'reason' => $validated['reason'] ?? 'Leave filed by administrator.',
                    'file_path' => $filePath,
                    'status' => 'approved',
                    'approved_by' => $request->user()->id,
                    'approved_at' => now(),
                ]);

                $record->leaveDates()->createMany(collect($dates)->map(fn (array $date): array => [
                    ...$date,
                    'approved_pay_type' => $date['requested_pay_type'],
                ])->all());

                if ($policy && $paidDays > 0) {
                    $credits->deductApprovedLeave(
                        $lockedEmployee,
                        $policy,
                        $record,
                        $paidDays,
                        $year,
                        $firstDate,
                        (int) $request->user()->id
                    );
                }

                return $record->fresh([
                    'employee:id,emp_id,department_id,firstname,lastname',
                    'employee.department:id,department_name',
                    'employee.latestEmployment.position:id,position',
                    'leaveType:id,leave_code,leave_name',
                    'leavePolicy:id,leave_type_id,employment_type_id,is_eligible,is_paid,status',
                    'leaveDates',
                    'approver:id,name,email',
                ]);
            });
        } catch (Throwable $exception) {
            if ($filePath && $filePath !== ($validated['file_path'] ?? null)) {
                Storage::disk('public')->delete($filePath);
            }

            throw $exception;
        }

        return response()->json([
            'success' => true,
            'message' => 'Employee leave filed and approved successfully.',
            'data' => $this->formatRequest($leaveRequest),
        ], 201);
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

    private function validateAdminFiling(Request $request): array
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],
            'request_date' => ['nullable', 'date'],
            'start_date' => ['nullable', 'date'],
            'start' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'end' => ['nullable', 'date'],
            'requested_pay_type' => ['required', 'in:paid,unpaid,mixed'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'dates' => ['nullable', 'array', 'min:1'],
            'dates.*' => ['required', 'array'],
            'dates.*.leave_date' => ['required', 'date', 'distinct'],
            'dates.*.day_type' => ['required', 'in:whole_day,half_day'],
            'dates.*.day_value' => ['nullable', 'numeric', 'in:0.5,1'],
            'dates.*.requested_pay_type' => ['nullable', 'in:paid,unpaid'],
            'file_path' => ['nullable', 'string', 'max:500'],
            'file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'image' => ['nullable'],
        ]);

        if (empty($validated['dates'])
            && empty($validated['start_date'])
            && empty($validated['start'])) {
            throw ValidationException::withMessages([
                'dates' => 'Provide at least one leave date or a start and end date range.',
            ]);
        }

        return $validated;
    }

    private function normalizeAdminDates(
        Employee $employee,
        array $validated,
        LeaveCreditService $credits
    ): array {
        $parentPayType = $validated['requested_pay_type'];

        if (! empty($validated['dates'])) {
            $dates = collect($validated['dates'])->map(function (array $date) use ($parentPayType): array {
                $dayType = $date['day_type'];
                $dayValue = $dayType === 'half_day' ? 0.5 : 1.0;

                if (isset($date['day_value']) && (float) $date['day_value'] !== $dayValue) {
                    throw ValidationException::withMessages([
                        'dates' => 'Day value must be 1.00 for a whole day or 0.50 for a half day.',
                    ]);
                }

                $datePayType = $date['requested_pay_type'] ?? null;
                if (! $datePayType && in_array($parentPayType, ['paid', 'unpaid'], true)) {
                    $datePayType = $parentPayType;
                }

                if (! in_array($datePayType, ['paid', 'unpaid'], true)) {
                    throw ValidationException::withMessages([
                        'dates' => 'Every date in a mixed request must be explicitly marked paid or unpaid.',
                    ]);
                }

                return [
                    'leave_date' => Carbon::parse($date['leave_date'])->toDateString(),
                    'day_type' => $dayType,
                    'day_value' => $dayValue,
                    'requested_pay_type' => $datePayType,
                    'approved_pay_type' => null,
                ];
            })->values();
        } else {
            if ($parentPayType === 'mixed') {
                throw ValidationException::withMessages([
                    'dates' => 'A mixed request requires a paid or unpaid classification for every date.',
                ]);
            }

            $startDate = $validated['start_date'] ?? $validated['start'];
            $endDate = $validated['end_date'] ?? $validated['end'] ?? $startDate;
            $start = Carbon::parse($startDate);
            $end = Carbon::parse($endDate);

            if ($end->lessThan($start)) {
                throw ValidationException::withMessages([
                    'end_date' => 'The end date must be on or after the start date.',
                ]);
            }

            $dates = collect();
            for ($date = $start->copy(); $date->lessThanOrEqualTo($end); $date->addDay()) {
                $dates->push([
                    'leave_date' => $date->toDateString(),
                    'day_type' => 'whole_day',
                    'day_value' => 1.0,
                    'requested_pay_type' => $parentPayType,
                    'approved_pay_type' => null,
                ]);
            }
        }

        if ($dates->pluck('leave_date')->unique()->count() !== $dates->count()) {
            throw ValidationException::withMessages([
                'dates' => 'Duplicate leave dates are not allowed.',
            ]);
        }

        if ($dates->pluck('leave_date')->map(fn ($date) => Carbon::parse($date)->year)->unique()->count() > 1) {
            throw ValidationException::withMessages([
                'dates' => 'Leave dates spanning different years require separate requests.',
            ]);
        }

        $workingDates = $credits->filterWorkingDates(
            $employee,
            $dates->sortBy('leave_date')->values()->all()
        );

        if (empty($workingDates)) {
            throw ValidationException::withMessages([
                'dates' => 'The selected leave period contains no scheduled working days for this employee.',
            ]);
        }

        return $workingDates;
    }

    private function deriveAdminPayType(array $dates, string $suppliedPayType): string
    {
        $types = collect($dates)->pluck('requested_pay_type')->unique()->values();
        $derived = $types->count() > 1 ? 'mixed' : (string) $types->first();

        if ($suppliedPayType !== $derived) {
            throw ValidationException::withMessages([
                'requested_pay_type' => "The request payment type must be {$derived} based on its dates.",
            ]);
        }

        return $derived;
    }

    private function selectedCalendarDateCount(array $validated): int
    {
        if (! empty($validated['dates'])) {
            return count($validated['dates']);
        }

        $startDate = $validated['start_date'] ?? $validated['start'];
        $endDate = $validated['end_date'] ?? $validated['end'] ?? $startDate;

        return Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) + 1;
    }

    private function handleAdminFileUpload(Request $request, int $employeeId): ?string
    {
        $file = $request->file('file') ?? $request->file('image');
        if ($file && $file->isValid()) {
            return $file->store("requests/{$employeeId}", 'public');
        }

        $imageData = $request->input('image.dataUrl')
            ?? (is_string($request->input('image')) ? $request->input('image') : null);

        if (! $imageData || ! is_string($imageData) || ! str_starts_with($imageData, 'data:image')) {
            return null;
        }

        $extension = 'jpg';
        if (preg_match('/^data:(image\/[a-zA-Z0-9\+\-]+);base64,/', $imageData, $matches)) {
            $extension = match ($matches[1]) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
                default => 'jpg',
            };
            $imageData = substr($imageData, strpos($imageData, ',') + 1);
        }

        $binary = base64_decode($imageData, true);
        if ($binary === false) {
            throw ValidationException::withMessages([
                'image' => 'The supplied image could not be decoded.',
            ]);
        }

        $path = "requests/{$employeeId}/admin_".Str::random(16).".{$extension}";
        Storage::disk('public')->put($path, $binary);

        return $path;
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
                    ($request->employee?->firstname ?? '').' '.
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
