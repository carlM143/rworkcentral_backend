<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\OvertimeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OvertimeController extends Controller
{
    public function index(): JsonResponse
    {
        $requests = OvertimeRequest::query()
            ->with([
                'employee:id,emp_id,firstname,lastname',
                'workSchedule:id,schedule_code,schedule_name,time_in,time_out',
                'timeLog:id,employee_id,work_date,time_in,time_out,overtime_minutes,status',
                'approver:id,name,email',
            ])
            ->latest('id')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Overtime requests retrieved successfully.',
            'data' => $requests,
        ]);
    }

    public function approve(Request $request, OvertimeRequest $overtimeRequest): JsonResponse
    {
        $approved = DB::transaction(function () use ($request, $overtimeRequest): OvertimeRequest {
            $record = OvertimeRequest::query()
                ->lockForUpdate()
                ->findOrFail($overtimeRequest->id);

            if ($record->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => 'Only pending overtime requests can be approved.',
                ]);
            }

            if ($record->end_at->lessThanOrEqualTo($record->start_at)) {
                throw ValidationException::withMessages([
                    'end_at' => 'Overtime end time must be after its start time.',
                ]);
            }

            $record->update([
                'status' => 'approved',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);

            return $record->fresh([
                'employee:id,emp_id,firstname,lastname',
                'workSchedule:id,schedule_code,schedule_name,time_in,time_out',
                'timeLog',
                'approver:id,name,email',
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Overtime request approved successfully.',
            'data' => $approved,
        ]);
    }
}
