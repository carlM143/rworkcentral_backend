<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\UndertimeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UndertimeController extends Controller
{
    public function index(): JsonResponse
    {
        $requests = UndertimeRequest::query()
            ->with([
                'employee:id,emp_id,firstname,lastname',
                'workSchedule:id,schedule_code,schedule_name,time_in,time_out',
                'timeLog:id,employee_id,work_date,time_in,time_out,undertime_minutes,status',
                'approver:id,name,email',
            ])
            ->latest('id')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Undertime requests retrieved successfully.',
            'data' => $requests,
        ]);
    }

    public function approve(Request $request, UndertimeRequest $undertimeRequest): JsonResponse
    {
        $approved = DB::transaction(function () use ($request, $undertimeRequest): UndertimeRequest {
            $record = UndertimeRequest::query()
                ->lockForUpdate()
                ->findOrFail($undertimeRequest->id);

            if ($record->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => 'Only pending undertime requests can be approved.',
                ]);
            }

            if ($record->requested_minutes < 1) {
                throw ValidationException::withMessages([
                    'requested_minutes' => 'Requested undertime minutes must be greater than zero.',
                ]);
            }

            if ($record->requested_time_out->greaterThanOrEqualTo($record->scheduled_time_out)) {
                throw ValidationException::withMessages([
                    'requested_time_out' => 'Requested time out must be earlier than the scheduled time out.',
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
            'message' => 'Undertime request approved successfully.',
            'data' => $approved,
        ]);
    }
}
