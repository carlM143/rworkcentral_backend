<?php

namespace App\Http\Controllers\Api\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreUndertimeRequest;
use App\Models\UndertimeRequest;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UndertimeRequestController extends Controller
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

        $query = $employee->undertimeRequests()->with(['workSchedule', 'timeLog']);

        if ($request->filled('status')) {
            $query->where('status', strtolower($request->string('status')->trim()->toString()));
        }

        return response()->json([
            'success' => true,
            'message' => 'Undertime requests retrieved successfully.',
            'data' => $query->latest('id')->get(),
        ]);
    }

    public function store(StoreUndertimeRequest $request): JsonResponse
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

        $scheduledTimeOut = $validated['scheduled_time_out'] ?? null;
        if (! $scheduledTimeOut) {
            $scheduledTimeOut = Carbon::parse($validated['undertime_date'])->setTime(17, 0, 0)->toDateTimeString();
        }

        $requestedMinutes = $validated['requested_minutes'] ?? null;
        if (! $requestedMinutes) {
            $reqTime = Carbon::parse($validated['requested_time_out']);
            $schedTime = Carbon::parse($scheduledTimeOut);
            $requestedMinutes = max(0, $schedTime->diffInMinutes($reqTime));
        }

        $requestNo = 'UT-' . date('Ymd') . '-' . strtoupper(Str::random(6));

        $utRequest = $employee->undertimeRequests()->create([
            'work_schedule_id' => $scheduleId,
            'request_no' => $requestNo,
            'undertime_date' => $validated['undertime_date'],
            'requested_time_out' => $validated['requested_time_out'],
            'scheduled_time_out' => $scheduledTimeOut,
            'requested_minutes' => $requestedMinutes,
            'reason' => $validated['reason'],
            'file_path' => $validated['file_path'] ?? null,
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Undertime request submitted successfully.',
            'data' => $utRequest->load(['workSchedule', 'timeLog']),
        ], 201);
    }

    public function cancel(UndertimeRequest $undertimeRequest, Request $request): JsonResponse
    {
        $employee = $request->user()?->employee;

        if (! $employee || $undertimeRequest->employee_id !== $employee->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized action.',
            ], 403);
        }

        if ($undertimeRequest->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending undertime requests can be cancelled.',
            ], 422);
        }

        $undertimeRequest->update(['status' => 'cancelled']);

        return response()->json([
            'success' => true,
            'message' => 'Undertime request cancelled successfully.',
            'data' => $undertimeRequest->fresh(['workSchedule', 'timeLog']),
        ]);
    }
}
