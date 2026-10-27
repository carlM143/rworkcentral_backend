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
                'employee:id,emp_id,firstname,lastname',
                'leaveType:id,leave_code,leave_name',
                'leavePolicy:id,leave_type_id,employment_type_id,is_eligible,is_paid,status',
            ])
            ->latest('id')
            ->get()
            ->map(fn (LeaveRequest $item) => [
                'id' => $item->id,
                'request_no' => $item->request_no,
                'employee' => [
                    'id' => $item->employee?->id,
                    'emp_id' => $item->employee?->emp_id,
                    'name' => trim(($item->employee?->firstname ?? '') . ' ' . ($item->employee?->lastname ?? '')),
                ],
                'leave_type' => $item->leaveType?->leave_name ?? 'Unknown Leave Type',
                'leave_code' => $item->leaveType?->leave_code,
                'leave_policy_id' => $item->leave_policy_id,
                'policy_status' => $item->leavePolicy?->status,
                'policy_eligible' => $item->leavePolicy ? (bool) $item->leavePolicy->is_eligible : false,
                'policy_paid' => $item->leavePolicy ? (bool) $item->leavePolicy->is_paid : null,
                'request_date' => $item->request_date?->format('Y-m-d'),
                'requested_pay_type' => $item->requested_pay_type,
                'approved_pay_type' => $item->approved_pay_type,
                'reason' => $item->reason,
                'status' => $item->status,
                'approved_by' => $item->approved_by,
                'approved_at' => $item->approved_at,
            ]);

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
}
