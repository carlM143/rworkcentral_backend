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
                'employee:id,emp_id,firstname,middlename,lastname,department_id',
                'employee.department:id,department_name',
                'employee.employments.department:id,department_name',
                'employee.employments.position:id,position',
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
                'employee:id,emp_id,firstname,middlename,lastname,department_id',
                'employee.department:id,department_name',
                'employee.employments.department:id,department_name',
                'employee.employments.position:id,position',
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

    public function reject(Request $request, OvertimeRequest $overtimeRequest): JsonResponse
    {
        $rejected = DB::transaction(function () use ($request, $overtimeRequest): OvertimeRequest {
            $record = OvertimeRequest::query()
                ->lockForUpdate()
                ->findOrFail($overtimeRequest->id);

            if ($record->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => 'Only pending overtime requests can be rejected.',
                ]);
            }

            $record->update([
                'status' => 'rejected',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);

            return $record->fresh([
                'employee:id,emp_id,firstname,middlename,lastname,department_id',
                'employee.department:id,department_name',
                'employee.employments.department:id,department_name',
                'employee.employments.position:id,position',
                'workSchedule:id,schedule_code,schedule_name,time_in,time_out',
                'timeLog',
                'approver:id,name,email',
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Overtime request rejected successfully.',
            'data' => $rejected,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'ot_date' => 'required|date',
            'start_at' => 'required|date',
            'end_at' => 'required|date|after:start_at',
            'reason' => 'nullable|string',
            'image' => 'nullable',
        ]);

        $employee = \App\Models\Employee::findOrFail($validated['employee_id']);
        $scheduleId = $employee->employments()->latest('id')->first()?->work_schedule_id
            ?? \App\Models\WorkSchedule::first()?->id;

        if (!$scheduleId) {
            $fallback = \App\Models\WorkSchedule::create([
                'schedule_code' => 'STD-DEF',
                'schedule_name' => 'Standard Regular Schedule',
                'time_in' => '09:00:00',
                'time_out' => '18:00:00',
            ]);
            $scheduleId = $fallback->id;
        }

        $requestNo = 'OT-' . date('Ymd') . '-' . strtoupper(\Illuminate\Support\Str::random(6));
        $filePath = $this->handleFileUpload($request, $validated['employee_id']);

        $overtimeRequest = OvertimeRequest::create([
            'employee_id' => $validated['employee_id'],
            'work_schedule_id' => $scheduleId,
            'request_no' => $requestNo,
            'ot_date' => $validated['ot_date'],
            'start_at' => $validated['start_at'],
            'end_at' => $validated['end_at'],
            'reason' => $validated['reason'] ?? 'Admin filed overtime request',
            'file_path' => $filePath,
            'status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Overtime request filed and auto-approved successfully.',
            'data' => $overtimeRequest,
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

    public function revert(Request $request, OvertimeRequest $overtimeRequest): JsonResponse
    {
        $reverted = DB::transaction(function () use ($request, $overtimeRequest): OvertimeRequest {
            $record = OvertimeRequest::query()
                ->lockForUpdate()
                ->findOrFail($overtimeRequest->id);

            $record->update([
                'status' => 'pending',
                'approved_by' => null,
                'approved_at' => null,
            ]);

            return $record->fresh([
                'employee:id,emp_id,firstname,middlename,lastname,department_id',
                'employee.department:id,department_name',
                'employee.employments.department:id,department_name',
                'employee.employments.position:id,position',
                'workSchedule:id,schedule_code,schedule_name,time_in,time_out',
                'timeLog',
                'approver:id,name,email',
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Overtime request reverted to pending successfully.',
            'data' => $reverted,
        ]);
    }
}
