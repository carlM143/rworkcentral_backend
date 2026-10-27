<?php

namespace App\Http\Controllers\Api\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreOvertimeRequest;
use App\Models\OvertimeRequest;
use App\Models\WorkSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OvertimeRequestController extends Controller
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

        $query = $employee->overtimeRequests()->with(['workSchedule', 'timeLog']);

        if ($request->filled('status')) {
            $query->where('status', strtolower($request->string('status')->trim()->toString()));
        }

        return response()->json([
            'success' => true,
            'message' => 'Overtime requests retrieved successfully.',
            'data' => $query->latest('id')->get(),
        ]);
    }

    public function store(StoreOvertimeRequest $request): JsonResponse
    {
        $employee = $request->user()?->employee;

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'No employee profile associated with this account.',
            ], 404);
        }

        $validated = $request->validated();
        $scheduleId = $validated['work_schedule_id'] ?? null;

        if (! $scheduleId) {
            $scheduleId = $employee->employments()->latest('id')->first()?->work_schedule_id
                ?? WorkSchedule::first()?->id;
        }

        if (! $scheduleId) {
            return response()->json([
                'success' => false,
                'message' => 'No work schedule assigned or found.',
            ], 422);
        }

        $requestNo = 'OT-' . date('Ymd') . '-' . strtoupper(Str::random(6));

        $otRequest = $employee->overtimeRequests()->create([
            'work_schedule_id' => $scheduleId,
            'request_no' => $requestNo,
            'ot_date' => $validated['ot_date'],
            'start_at' => $validated['start_at'],
            'end_at' => $validated['end_at'],
            'reason' => $validated['reason'] ?? null,
            'file_path' => $validated['file_path'] ?? null,
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Overtime request submitted successfully.',
            'data' => $otRequest->load(['workSchedule', 'timeLog']),
        ], 201);
    }

    public function cancel(OvertimeRequest $overtimeRequest, Request $request): JsonResponse
    {
        $employee = $request->user()?->employee;

        if (! $employee || $overtimeRequest->employee_id !== $employee->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized action.',
            ], 403);
        }

        if ($overtimeRequest->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending overtime requests can be cancelled.',
            ], 422);
        }

        $overtimeRequest->update(['status' => 'cancelled']);

        return response()->json([
            'success' => true,
            'message' => 'Overtime request cancelled successfully.',
            'data' => $overtimeRequest->fresh(['workSchedule', 'timeLog']),
        ]);
    }
}
