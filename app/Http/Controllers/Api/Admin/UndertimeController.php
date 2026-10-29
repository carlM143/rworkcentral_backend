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
                'employee:id,emp_id,firstname,middlename,lastname,department_id',
                'employee.department:id,department_name',
                'employee.employments.department:id,department_name',
                'employee.employments.position:id,position',
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
            'message' => 'Undertime request approved successfully.',
            'data' => $approved,
        ]);
    }

    public function reject(Request $request, UndertimeRequest $undertimeRequest): JsonResponse
    {
        $rejected = DB::transaction(function () use ($request, $undertimeRequest): UndertimeRequest {
            $record = UndertimeRequest::query()
                ->lockForUpdate()
                ->findOrFail($undertimeRequest->id);

            if ($record->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => 'Only pending undertime requests can be rejected.',
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
            'message' => 'Undertime request rejected successfully.',
            'data' => $rejected,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'undertime_date' => 'required|date',
            'scheduled_time_out' => 'required',
            'requested_time_out' => 'required',
            'reason' => 'nullable|string',
            'image' => 'nullable',
        ]);

        $start = \Carbon\Carbon::parse($validated['scheduled_time_out']);
        $end = \Carbon\Carbon::parse($validated['requested_time_out']);
        $minutes = max(0, (int) $start->diffInMinutes($end, false));

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

        $requestNo = 'UT-' . date('Ymd') . '-' . strtoupper(\Illuminate\Support\Str::random(6));
        $filePath = $this->handleFileUpload($request, $validated['employee_id']);

        $undertimeRequest = UndertimeRequest::create([
            'employee_id' => $validated['employee_id'],
            'work_schedule_id' => $scheduleId,
            'request_no' => $requestNo,
            'undertime_date' => $validated['undertime_date'],
            'scheduled_time_out' => $validated['scheduled_time_out'],
            'requested_time_out' => $validated['requested_time_out'],
            'requested_minutes' => $minutes > 0 ? $minutes : 60,
            'reason' => $validated['reason'] ?? 'Admin filed undertime request',
            'file_path' => $filePath,
            'status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Undertime request filed and auto-approved successfully.',
            'data' => $undertimeRequest,
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

    public function revert(Request $request, UndertimeRequest $undertimeRequest): JsonResponse
    {
        $reverted = DB::transaction(function () use ($request, $undertimeRequest): UndertimeRequest {
            $record = UndertimeRequest::query()
                ->lockForUpdate()
                ->findOrFail($undertimeRequest->id);

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
            'message' => 'Undertime request reverted to pending successfully.',
            'data' => $reverted,
        ]);
    }
}
