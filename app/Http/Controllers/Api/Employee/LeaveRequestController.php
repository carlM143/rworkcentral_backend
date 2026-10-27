<?php

namespace App\Http\Controllers\Api\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreLeaveRequest;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LeaveRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $employee = $request->user()?->employee;

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'No employee profile associated with this account.',
            ], 404);
        }

        $query = $employee->leaveRequests()->with(['leaveType', 'leavePolicy', 'leaveDates']);

        if ($request->filled('status')) {
            $query->where('status', strtolower($request->string('status')->trim()->toString()));
        }

        return response()->json([
            'success' => true,
            'message' => 'Leave requests retrieved successfully.',
            'data' => $query->latest('id')->get(),
        ]);
    }

    public function types(Request $request): JsonResponse
    {
        $employee = $request->user()?->employee;
        $employmentType = $employee?->employments()->latest('id')->first()?->employmentType;

        $query = LeaveType::query()->where('status', 'active')->with(['leavePolicies' => function ($q) use ($employmentType) {
            if ($employmentType) {
                $q->where('employment_type_id', $employmentType->id);
            }
        }]);

        return response()->json([
            'success' => true,
            'message' => 'Leave types retrieved successfully.',
            'data' => $query->get(),
        ]);
    }

    public function store(StoreLeaveRequest $request): JsonResponse
    {
        $employee = $request->user()?->employee;

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'No employee profile associated with this account.',
            ], 404);
        }

        $validated = $request->validated();
        $policyId = $validated['leave_policy_id'] ?? null;

        if (! $policyId) {
            $employmentTypeId = $employee->employments()->latest('id')->first()?->employment_type_id;

            $policy = LeavePolicy::query()
                ->where('leave_type_id', $validated['leave_type_id'])
                ->when($employmentTypeId, fn ($q) => $q->where('employment_type_id', $employmentTypeId))
                ->first()
                ?? LeavePolicy::where('leave_type_id', $validated['leave_type_id'])->first();

            $policyId = $policy?->id;
        }

        if (! $policyId) {
            return response()->json([
                'success' => false,
                'message' => 'No leave policy found for the selected leave type.',
            ], 422);
        }

        $requestNo = 'LR-' . date('Ymd') . '-' . strtoupper(Str::random(6));

        $leaveRequest = $employee->leaveRequests()->create([
            'leave_type_id' => $validated['leave_type_id'],
            'leave_policy_id' => $policyId,
            'request_no' => $requestNo,
            'request_date' => $validated['request_date'],
            'reason' => $validated['reason'] ?? null,
            'file_path' => $validated['file_path'] ?? null,
            'status' => 'pending',
        ]);

        if (! empty($validated['dates']) && is_array($validated['dates'])) {
            foreach ($validated['dates'] as $date) {
                $rawDayType = is_array($date) ? ($date['day_type'] ?? 'whole_day') : 'whole_day';
                $dayType = in_array($rawDayType, ['whole_day', 'half_day']) ? $rawDayType : ($rawDayType === 'half' ? 'half_day' : 'whole_day');
                $leaveRequest->leaveDates()->create([
                    'leave_date' => is_array($date) ? ($date['leave_date'] ?? $date['date']) : $date,
                    'day_type' => $dayType,
                    'day_value' => is_array($date) ? ($date['day_value'] ?? ($dayType === 'half_day' ? 0.5 : 1.0)) : 1.0,
                ]);
            }
        } else {
            $leaveRequest->leaveDates()->create([
                'leave_date' => $validated['request_date'],
                'day_type' => 'whole_day',
                'day_value' => 1.0,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Leave request submitted successfully.',
            'data' => $leaveRequest->load(['leaveType', 'leavePolicy', 'leaveDates']),
        ], 201);
    }

    public function cancel(LeaveRequest $leaveRequest, Request $request): JsonResponse
    {
        $employee = $request->user()?->employee;

        if (! $employee || $leaveRequest->employee_id !== $employee->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized action.',
            ], 403);
        }

        if ($leaveRequest->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending leave requests can be cancelled.',
            ], 422);
        }

        $leaveRequest->update(['status' => 'cancelled']);

        return response()->json([
            'success' => true,
            'message' => 'Leave request cancelled successfully.',
            'data' => $leaveRequest->fresh(['leaveType', 'leavePolicy', 'leaveDates']),
        ]);
    }
}
