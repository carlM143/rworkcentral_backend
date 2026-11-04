<?php

namespace App\Http\Controllers\Api\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreLeaveRequest;
use App\Models\Employee;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\LeaveCreditService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class LeaveRequestController extends Controller
{
    public function __construct(private LeaveCreditService $credits) {}

    public function index(Request $request): JsonResponse
    {
        $employee = $this->authenticatedEmployee($request);
        $query = $employee->leaveRequests()->with(['leaveType', 'leavePolicy', 'leaveDates']);

        if ($request->filled('status')) {
            $query->where('status', strtolower($request->string('status')->trim()->toString()));
        }

        return response()->json([
            'success' => true,
            'message' => 'Leave requests retrieved successfully.',
            'data' => $query->latest('id')->get()->map(
                fn (LeaveRequest $leaveRequest) => $this->formatLeaveRequest($leaveRequest)
            ),
        ]);
    }

    public function preview(StoreLeaveRequest $request): JsonResponse
    {
        $employee = $this->authenticatedEmployee($request);
        $validated = $request->validated();
        $dates = $this->normalizeDates($employee, $validated);
        $calendarDateCount = $this->selectedCalendarDateCount($validated);

        return response()->json([
            'success' => true,
            'message' => 'Leave schedule calculated successfully.',
            'data' => [
                'calendar_days' => $calendarDateCount,
                'scheduled_working_days' => count($dates),
                'excluded_rest_days' => max(0, $calendarDateCount - count($dates)),
                'total_days' => round((float) collect($dates)->sum('day_value'), 2),
                'dates' => $dates,
            ],
        ]);
    }

    public function store(StoreLeaveRequest $request): JsonResponse
    {
        $employee = $this->authenticatedEmployee($request);
        $validated = $request->validated();
        $leaveType = $this->resolveActiveLeaveType($validated);
        $dates = $this->normalizeDates($employee, $validated);
        $payType = $this->derivePayType($dates, $validated['requested_pay_type'] ?? null);
        $policy = $this->validateRequest($employee, $leaveType->id, $dates);
        $filePath = $this->handleFileUpload($request, $employee->id)
            ?? ($validated['file_path'] ?? null);

        try {
            $leaveRequest = DB::transaction(function () use (
                $employee,
                $leaveType,
                $policy,
                $payType,
                $dates,
                $validated,
                $filePath
            ): LeaveRequest {
                $leaveRequest = $employee->leaveRequests()->create([
                    'leave_type_id' => $leaveType->id,
                    'leave_policy_id' => $policy?->id,
                    'request_no' => 'LR-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
                    'request_date' => $validated['request_date'] ?? now()->toDateString(),
                    'requested_pay_type' => $payType,
                    'reason' => $validated['reason'] ?? null,
                    'file_path' => $filePath,
                    'status' => 'pending',
                ]);

                $leaveRequest->leaveDates()->createMany($dates);

                return $leaveRequest;
            });
        } catch (Throwable $exception) {
            if ($filePath && $filePath !== ($validated['file_path'] ?? null)) {
                Storage::disk('public')->delete($filePath);
            }

            throw $exception;
        }

        return response()->json([
            'success' => true,
            'message' => 'Leave request submitted successfully.',
            'data' => $this->formatLeaveRequest(
                $leaveRequest->load(['leaveType', 'leavePolicy', 'leaveDates'])
            ),
        ], 201);
    }

    public function update(LeaveRequest $leaveRequest, StoreLeaveRequest $request): JsonResponse
    {
        $employee = $this->authenticatedEmployee($request);
        $this->ensureEmployeeOwnsPendingRequest($employee, $leaveRequest);
        $validated = $request->validated();
        $leaveType = $this->resolveActiveLeaveType($validated, $leaveRequest->leave_type_id);
        $dates = $this->datesForUpdate($employee, $leaveRequest, $validated);
        $payType = $this->derivePayType($dates, $validated['requested_pay_type'] ?? null);
        $policy = $this->validateRequest($employee, $leaveType->id, $dates, $leaveRequest->id);
        $newFilePath = $this->handleFileUpload($request, $employee->id);
        $oldFilePath = $leaveRequest->file_path;
        $removeFile = $request->boolean('remove_image')
            || ($request->has('image')
                && is_null($request->input('image'))
                && ! $request->hasFile('file')
                && ! $request->hasFile('image'));

        try {
            DB::transaction(function () use (
                $leaveRequest,
                $leaveType,
                $policy,
                $payType,
                $dates,
                $validated,
                $newFilePath,
                $removeFile
            ): void {
                $updates = [
                    'leave_type_id' => $leaveType->id,
                    'leave_policy_id' => $policy?->id,
                    'requested_pay_type' => $payType,
                ];

                if (array_key_exists('request_date', $validated)) {
                    $updates['request_date'] = $validated['request_date'];
                }

                if (array_key_exists('reason', $validated)) {
                    $updates['reason'] = $validated['reason'];
                }

                if ($removeFile) {
                    $updates['file_path'] = null;
                } elseif ($newFilePath) {
                    $updates['file_path'] = $newFilePath;
                }

                $leaveRequest->update($updates);
                $leaveRequest->leaveDates()->delete();
                $leaveRequest->leaveDates()->createMany($dates);
            });
        } catch (Throwable $exception) {
            if ($newFilePath) {
                Storage::disk('public')->delete($newFilePath);
            }

            throw $exception;
        }

        if (($removeFile || $newFilePath) && $oldFilePath && $oldFilePath !== $newFilePath) {
            Storage::disk('public')->delete($oldFilePath);
        }

        return response()->json([
            'success' => true,
            'message' => 'Leave request updated successfully.',
            'data' => $this->formatLeaveRequest(
                $leaveRequest->fresh(['leaveType', 'leavePolicy', 'leaveDates'])
            ),
        ]);
    }

    public function cancel(LeaveRequest $leaveRequest, Request $request): JsonResponse
    {
        $employee = $this->authenticatedEmployee($request);
        $this->ensureEmployeeOwnsPendingRequest($employee, $leaveRequest);
        $leaveRequest->update(['status' => 'cancelled']);

        return response()->json([
            'success' => true,
            'message' => 'Leave request cancelled successfully.',
            'data' => $this->formatLeaveRequest(
                $leaveRequest->fresh(['leaveType', 'leavePolicy', 'leaveDates'])
            ),
        ]);
    }

    private function authenticatedEmployee(Request $request): Employee
    {
        $employee = $request->user()?->employee;

        if (! $employee) {
            abort(404, 'No employee profile associated with this account.');
        }

        return $employee;
    }

    private function resolveActiveLeaveType(array $validated, ?int $fallbackId = null): LeaveType
    {
        $query = LeaveType::query()->where('status', 'active');

        if (! empty($validated['leave_type_id'])) {
            $leaveType = (clone $query)->find($validated['leave_type_id']);
        } elseif (! empty($validated['type'])) {
            $leaveType = (clone $query)
                ->where(function ($query) use ($validated): void {
                    $query->where('leave_name', $validated['type'])
                        ->orWhere('leave_code', $validated['type']);
                })
                ->first();
        } elseif ($fallbackId) {
            $leaveType = (clone $query)->find($fallbackId);
        } else {
            $leaveType = null;
        }

        if (! $leaveType) {
            throw ValidationException::withMessages([
                'leave_type_id' => 'Please select an active leave type.',
            ]);
        }

        return $leaveType;
    }

    private function normalizeDates(Employee $employee, array $validated): array
    {
        $parentPayType = $validated['requested_pay_type'] ?? 'paid';

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

            $startDate = $validated['start_date']
                ?? $validated['start']
                ?? $validated['request_date']
                ?? now()->toDateString();
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

        $workingDates = $this->credits->filterWorkingDates(
            $employee,
            $dates->sortBy('leave_date')->values()->all()
        );

        if (empty($workingDates)) {
            throw ValidationException::withMessages([
                'dates' => 'The selected leave period contains no scheduled working days.',
            ]);
        }

        return $workingDates;
    }

    private function selectedCalendarDateCount(array $validated): int
    {
        if (! empty($validated['dates'])) {
            return count($validated['dates']);
        }

        $startDate = $validated['start_date']
            ?? $validated['start']
            ?? $validated['request_date']
            ?? now()->toDateString();
        $endDate = $validated['end_date'] ?? $validated['end'] ?? $startDate;

        return Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) + 1;
    }

    private function datesForUpdate(
        Employee $employee,
        LeaveRequest $leaveRequest,
        array $validated
    ): array {
        $hasNewDates = array_key_exists('dates', $validated)
            || isset($validated['start_date'])
            || isset($validated['start']);

        if ($hasNewDates) {
            return $this->normalizeDates($employee, $validated);
        }

        $requestedPayType = $validated['requested_pay_type'] ?? null;
        $existingDates = $leaveRequest->leaveDates()->orderBy('leave_date')->get();

        if ($existingDates->isEmpty()) {
            return $this->normalizeDates($employee, [
                'request_date' => $validated['request_date'] ?? $leaveRequest->request_date,
                'requested_pay_type' => $requestedPayType ?? $leaveRequest->requested_pay_type,
            ]);
        }

        return $existingDates->map(function ($date) use ($requestedPayType, $leaveRequest): array {
            $payType = $date->requested_pay_type;

            if ($requestedPayType && $requestedPayType !== 'mixed') {
                $payType = $requestedPayType;
            }

            $payType ??= in_array($leaveRequest->requested_pay_type, ['paid', 'unpaid'], true)
                ? $leaveRequest->requested_pay_type
                : null;

            if (! $payType) {
                throw ValidationException::withMessages([
                    'dates' => 'Every date in a mixed request must be explicitly marked paid or unpaid.',
                ]);
            }

            return [
                'leave_date' => $date->leave_date->toDateString(),
                'day_type' => $date->day_type,
                'day_value' => (float) $date->day_value,
                'requested_pay_type' => $payType,
                'approved_pay_type' => null,
            ];
        })->all();
    }

    private function derivePayType(array $dates, ?string $suppliedPayType): string
    {
        $types = collect($dates)->pluck('requested_pay_type')->unique()->values();
        $derived = $types->count() > 1 ? 'mixed' : (string) $types->first();

        if ($suppliedPayType && $suppliedPayType !== $derived) {
            throw ValidationException::withMessages([
                'requested_pay_type' => "The request payment type must be {$derived} based on its dates.",
            ]);
        }

        return $derived;
    }

    private function validateRequest(
        Employee $employee,
        int $leaveTypeId,
        array $dates,
        ?int $ignoreRequestId = null
    ): ?LeavePolicy {
        $dateValues = collect($dates)->pluck('leave_date');
        $overlapQuery = DB::table('leave_request_dates as dates')
            ->join('leave_requests as requests', 'requests.id', '=', 'dates.leave_request_id')
            ->where('requests.employee_id', $employee->id)
            ->where('requests.status', 'approved')
            ->whereIn('dates.leave_date', $dateValues);

        if ($ignoreRequestId) {
            $overlapQuery->where('requests.id', '!=', $ignoreRequestId);
        }

        if ($overlapQuery->exists()) {
            throw ValidationException::withMessages([
                'dates' => 'One or more leave dates already have an approved leave request.',
            ]);
        }

        $paidDays = round((float) collect($dates)
            ->where('requested_pay_type', 'paid')
            ->sum('day_value'), 2);

        if ($paidDays <= 0) {
            return null;
        }

        $firstDate = (string) $dateValues->min();
        $lastDate = (string) $dateValues->max();
        $policy = $this->credits->resolvePaidPolicy(
            $employee,
            $leaveTypeId,
            $firstDate,
            $lastDate
        );

        if (! $policy) {
            throw ValidationException::withMessages([
                'leave_policy_id' => 'No eligible paid leave policy matches your employment type and service period.',
            ]);
        }

        if ($policy->usage_window !== 'calendar_year' || $policy->validity_period !== 'annual') {
            throw ValidationException::withMessages([
                'leave_policy_id' => 'This paid policy requires a different credit cycle calculation.',
            ]);
        }

        $year = Carbon::parse($firstDate)->year;
        $available = $this->credits->availableBalanceForSubmission($employee, $policy, $year);

        if ($paidDays > $available) {
            throw ValidationException::withMessages([
                'leave_credits' => "Insufficient paid leave credits for {$year}. Available: {$available}, Requested: {$paidDays}.",
            ]);
        }

        return $policy;
    }

    private function ensureEmployeeOwnsPendingRequest(Employee $employee, LeaveRequest $leaveRequest): void
    {
        abort_if($leaveRequest->employee_id !== $employee->id, 403, 'Unauthorized action.');

        if ($leaveRequest->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => 'Only pending leave requests can be updated or cancelled.',
            ]);
        }
    }

    private function formatLeaveRequest(LeaveRequest $request): array
    {
        $dates = $request->leaveDates->sortBy('leave_date')->values();
        $fileUrl = $request->file_path
            ? (str_starts_with($request->file_path, 'http')
                ? $request->file_path
                : asset('storage/'.ltrim($request->file_path, '/')))
            : null;

        return [
            'id' => $request->request_no ?: ('LR-'.$request->id),
            'raw_id' => $request->id,
            'request_no' => $request->request_no,
            'category' => 'Leave',
            'type' => $request->leaveType?->leave_name ?? 'Unknown Leave Type',
            'leave_type_id' => $request->leave_type_id,
            'leave_policy_id' => $request->leave_policy_id,
            'request_date' => $request->request_date?->format('Y-m-d'),
            'requested_pay_type' => $request->requested_pay_type,
            'approved_pay_type' => $request->approved_pay_type,
            'start' => $dates->first()?->leave_date?->format('Y-m-d'),
            'end' => $dates->last()?->leave_date?->format('Y-m-d'),
            'reason' => $request->reason ?? '',
            'status' => $request->status,
            'status_label' => ucfirst($request->status),
            'file_path' => $request->file_path,
            'file_url' => $fileUrl,
            'image' => $fileUrl ? [
                'name' => basename($request->file_path),
                'dataUrl' => $fileUrl,
            ] : null,
            'dates' => $dates->map(fn ($date) => [
                'id' => $date->id,
                'leave_date' => $date->leave_date?->format('Y-m-d'),
                'day_type' => $date->day_type,
                'day_value' => (float) $date->day_value,
                'requested_pay_type' => $date->requested_pay_type,
                'approved_pay_type' => $date->approved_pay_type,
            ])->all(),
            'total_days' => round((float) $dates->sum('day_value'), 2),
            'total_paid_days' => round((float) $dates
                ->where('requested_pay_type', 'paid')->sum('day_value'), 2),
            'total_unpaid_days' => round((float) $dates
                ->where('requested_pay_type', 'unpaid')->sum('day_value'), 2),
            'created_at' => $request->created_at?->toIso8601String(),
        ];
    }

    private function handleFileUpload(Request $request, int $employeeId): ?string
    {
        $file = $request->file('file') ?? $request->file('image');
        if ($file && $file->isValid()) {
            return $file->store("requests/{$employeeId}", 'public');
        }

        $imageData = $request->input('image.dataUrl')
            ?? (is_string($request->input('image')) ? $request->input('image') : null)
            ?? $request->input('file_base64');

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
            return null;
        }

        $path = "requests/{$employeeId}/req_".Str::random(16).".{$extension}";
        Storage::disk('public')->put($path, $binary);

        return $path;
    }
}
