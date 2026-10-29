<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveApprovalController extends Controller
{
    public function index(): JsonResponse
    {
        $requests = LeaveRequest::query()
            ->with([
                'employee:id,emp_id,firstname,middlename,lastname,department_id',
                'employee.department:id,department_name',
                'employee.employments.department:id,department_name',
                'employee.employments.position:id,position',
                'leaveType:id,leave_code,leave_name',
                'leavePolicy:id,leave_type_id,employment_type_id,is_eligible,is_paid,status',
                'approver:id,name,email',
                'leaveDates:id,leave_request_id,leave_date,day_type,day_value',
            ])
            ->latest('id')
            ->get()
            ->map(function (LeaveRequest $item) {
                $latestEmployment = $item->employee?->employments?->sortBy('id')->last();
                $departmentName = $latestEmployment?->department?->department_name
                    ?? $item->employee?->department?->department_name;
                $positionName = $latestEmployment?->position?->position;

                $dates = $item->leaveDates ? $item->leaveDates->sortBy('leave_date') : collect();
                $firstDateObj = $dates->first();
                $lastDateObj = $dates->last();

                $requestDateStr = is_string($item->request_date)
                    ? substr($item->request_date, 0, 10)
                    : $item->request_date?->format('Y-m-d');

                $firstDate = $firstDateObj?->leave_date
                    ? (is_string($firstDateObj->leave_date) ? substr($firstDateObj->leave_date, 0, 10) : $firstDateObj->leave_date->format('Y-m-d'))
                    : $requestDateStr;

                $lastDate = $lastDateObj?->leave_date
                    ? (is_string($lastDateObj->leave_date) ? substr($lastDateObj->leave_date, 0, 10) : $lastDateObj->leave_date->format('Y-m-d'))
                    : $firstDate;

                return [
                    'id' => $item->id,
                    'request_no' => $item->request_no,
                    'employee' => [
                        'id' => $item->employee?->id,
                        'emp_id' => $item->employee?->emp_id,
                        'name' => trim(($item->employee?->firstname ?? '') . ' ' . ($item->employee?->lastname ?? '')),
                        'department' => $departmentName,
                        'position' => $positionName,
                    ],
                    'leave_type' => $item->leaveType?->leave_name ?? 'Unknown Leave Type',
                    'leave_code' => $item->leaveType?->leave_code,
                    'leave_policy_id' => $item->leave_policy_id,
                    'policy_status' => $item->leavePolicy?->status,
                    'policy_eligible' => $item->leavePolicy ? (bool) $item->leavePolicy->is_eligible : false,
                    'policy_paid' => $item->leavePolicy ? (bool) $item->leavePolicy->is_paid : null,
                    'request_date' => $requestDateStr,
                    'start' => $firstDate,
                    'end' => $lastDate,
                    'dates' => $dates->map(fn ($d) => [
                        'id' => $d->id,
                        'leave_date' => is_string($d->leave_date) ? substr($d->leave_date, 0, 10) : $d->leave_date?->format('Y-m-d'),
                        'day_type' => $d->day_type,
                        'day_value' => (float) $d->day_value,
                    ])->values()->all(),
                    'requested_pay_type' => $item->requested_pay_type,
                    'approved_pay_type' => $item->approved_pay_type,
                    'total_days' => (float) ($dates->sum('day_value') ?: 1),
                    'reason' => $item->reason,
                    'status' => $item->status,
                    'approved_by' => $item->approved_by,
                    'approver_name' => $item->approver?->name,
                    'approved_at' => $item->approved_at,
                    'created_at' => $item->created_at?->toIso8601String(),
                ];
            });

        return response()->json([
            'success' => true,
            'message' => 'Leave requests retrieved successfully.',
            'data' => $requests,
        ]);
    }

    public function approve(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $approved = DB::transaction(function () use ($request, $leaveRequest) {
            $record = LeaveRequest::query()
                ->lockForUpdate()
                ->findOrFail($leaveRequest->id);

            if ($record->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => 'Only pending leave requests can be approved.',
                ]);
            }

            $policy = LeavePolicy::find($record->leave_policy_id);

            if (!$policy || $policy->status !== 'approved' || !$policy->is_eligible) {
                throw ValidationException::withMessages([
                    'leave_policy_id' => 'The selected leave policy must be approved and eligible.',
                ]);
            }

            if ((int) $policy->leave_type_id !== (int) $record->leave_type_id) {
                throw ValidationException::withMessages([
                    'leave_type_id' => 'The leave type does not match the assigned leave policy.',
                ]);
            }

            $employmentTypeId = $record->employee
                ?->employments()
                ->latest('id')
                ->value('employment_type_id');

            if ((int) $employmentTypeId !== (int) $policy->employment_type_id) {
                throw ValidationException::withMessages([
                    'leave_policy_id' => 'The leave policy does not match the employee current employment type.',
                ]);
            }

            if (!in_array($record->requested_pay_type, ['paid', 'unpaid'], true)) {
                throw ValidationException::withMessages([
                    'requested_pay_type' => 'The requested payment type is invalid.',
                ]);
            }

            $isPaid = $record->requested_pay_type === 'paid';

            if ((bool) $policy->is_paid !== $isPaid) {
                throw ValidationException::withMessages([
                    'requested_pay_type' => 'The requested payment type does not match the selected leave policy.',
                ]);
            }

            $record->update([
                'approved_pay_type' => $record->requested_pay_type,
                'status' => 'approved',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);

            return $record->fresh();
        });

        return response()->json([
            'success' => true,
            'message' => 'Leave request approved successfully.',
            'data' => $approved,
        ]);
    }

    public function reject(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $rejected = DB::transaction(function () use ($request, $leaveRequest) {
            $record = LeaveRequest::query()
                ->lockForUpdate()
                ->findOrFail($leaveRequest->id);

            if ($record->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => 'Only pending leave requests can be rejected.',
                ]);
            }

            $record->update([
                'status' => 'rejected',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);

            return $record->fresh();
        });

        return response()->json([
            'success' => true,
            'message' => 'Leave request rejected successfully.',
            'data' => $rejected,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'leave_type_id' => 'required|exists:leave_types,id',
            'request_date' => 'required|date',
            'requested_pay_type' => 'nullable|in:paid,unpaid',
            'reason' => 'nullable|string',
            'dates' => 'nullable|array',
            'dates.*.leave_date' => 'required_with:dates|date',
            'dates.*.day_type' => 'nullable|in:whole_day,half_day',
            'dates.*.day_value' => 'nullable|numeric',
            'dates.*.pay_type' => 'nullable|in:paid,unpaid',
            'image' => 'nullable',
        ]);

        $employee = \App\Models\Employee::findOrFail($validated['employee_id']);
        $employmentTypeId = $employee->employments()->latest('id')->first()?->employment_type_id;

        $policy = \App\Models\LeavePolicy::query()
            ->where('leave_type_id', $validated['leave_type_id'])
            ->when($employmentTypeId, fn ($q) => $q->where('employment_type_id', $employmentTypeId))
            ->first()
            ?? \App\Models\LeavePolicy::where('leave_type_id', $validated['leave_type_id'])->first()
            ?? \App\Models\LeavePolicy::first();

        $requestNo = 'LR-' . date('Ymd') . '-' . strtoupper(\Illuminate\Support\Str::random(6));
        $payType = $validated['requested_pay_type'] ?? 'paid';
        $filePath = $this->handleFileUpload($request, $employee->id);

        $leaveRequest = LeaveRequest::create([
            'employee_id' => $validated['employee_id'],
            'leave_type_id' => $validated['leave_type_id'],
            'leave_policy_id' => $policy?->id,
            'request_no' => $requestNo,
            'request_date' => $validated['request_date'],
            'requested_pay_type' => $payType,
            'approved_pay_type' => $payType,
            'reason' => $validated['reason'] ?? 'Admin filed leave request',
            'file_path' => $filePath,
            'status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        if (!empty($validated['dates']) && is_array($validated['dates'])) {
            foreach ($validated['dates'] as $dateEntry) {
                $leaveDate = $dateEntry['leave_date'] ?? null;
                if (!$leaveDate) continue;
                $dayType = $dateEntry['day_type'] ?? 'whole_day';
                $dayValue = (float) ($dateEntry['day_value'] ?? ($dayType === 'half_day' ? 0.5 : 1.0));

                $leaveRequest->leaveDates()->firstOrCreate(
                    ['leave_date' => $leaveDate],
                    [
                        'day_type' => $dayType,
                        'day_value' => $dayValue,
                    ]
                );
            }
        } else {
            $leaveRequest->leaveDates()->firstOrCreate(
                ['leave_date' => $validated['request_date']],
                [
                    'day_type' => 'whole_day',
                    'day_value' => 1.0,
                ]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Leave request filed and auto-approved successfully.',
            'data' => $leaveRequest->load('leaveDates'),
        ], 201);
    }

    protected function handleFileUpload(Request $request, int $employeeId): ?string
    {
        $file = $request->file('file') ?? $request->file('image');
        if ($file && $file->isValid()) {
            return $file->store("requests/{$employeeId}", 'public');
        }

        $imageData = $request->input('image.dataUrl')
            ?? (is_string($request->input('image')) ? $request->input('image') : null);

        if ($imageData && is_string($imageData) && str_starts_with($imageData, 'data:image')) {
            if (preg_match('/^data:(image\/[a-zA-Z0-9\+\-]+);base64,/', $imageData, $matches)) {
                $extension = match ($matches[1]) {
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                    'image/gif' => 'gif',
                    default => 'jpg',
                };

                $imageData = substr($imageData, strpos($imageData, ',') + 1);
                $binary = base64_decode($imageData, true);

                if ($binary !== false) {
                    $fileName = 'req_' . time() . '_' . \Illuminate\Support\Str::random(10) . '.' . $extension;
                    $path = "requests/{$employeeId}/{$fileName}";
                    \Illuminate\Support\Facades\Storage::disk('public')->put($path, $binary);
                    return $path;
                }
            }
        }

        return null;
    }

    public function revert(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $reverted = DB::transaction(function () use ($request, $leaveRequest) {
            $record = LeaveRequest::query()
                ->lockForUpdate()
                ->findOrFail($leaveRequest->id);

            $record->update([
                'status' => 'pending',
                'approved_by' => null,
                'approved_at' => null,
            ]);

            return $record->fresh();
        });

        return response()->json([
            'success' => true,
            'message' => 'Leave request reverted to pending successfully.',
            'data' => $reverted,
        ]);
    }
}
