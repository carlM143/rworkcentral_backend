<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OvertimeController extends Controller
{
    public function index(): JsonResponse
    {
        $requests = OvertimeRequest::query()
            ->with($this->responseRelations())
            ->latest('id')
            ->get();

        $requests->each(fn (OvertimeRequest $overtimeRequest) => $this->appendCurrentPosition($overtimeRequest));

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

            return $this->appendCurrentPosition(
                $record->fresh($this->responseRelations())
            );
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

            return $this->appendCurrentPosition(
                $record->fresh($this->responseRelations())
            );
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

        $employee = Employee::findOrFail($validated['employee_id']);
        $scheduleId = $employee->employments()->latest('id')->first()?->work_schedule_id;

        if (! $scheduleId) {
            throw ValidationException::withMessages([
                'employee_id' => 'The selected employee does not have an assigned work schedule.',
            ]);
        }

        $requestNo = 'OT-'.date('Ymd').'-'.strtoupper(Str::random(6));
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

        $overtimeRequest = $this->appendCurrentPosition(
            $overtimeRequest->fresh($this->responseRelations())
        );

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
                    $fileName = 'req_'.time().'_'.Str::random(10).'.'.$extension;
                    $path = "requests/{$employeeId}/{$fileName}";
                    Storage::disk('public')->put($path, $binary);

                    return $path;
                }
            }
        }

        return null;
    }

    public function revert(Request $request, OvertimeRequest $overtimeRequest): JsonResponse
    {
        $reverted = DB::transaction(function () use ($overtimeRequest): OvertimeRequest {
            $record = OvertimeRequest::query()
                ->lockForUpdate()
                ->findOrFail($overtimeRequest->id);

            $record->update([
                'status' => 'pending',
                'approved_by' => null,
                'approved_at' => null,
            ]);

            return $this->appendCurrentPosition(
                $record->fresh($this->responseRelations())
            );
        });

        return response()->json([
            'success' => true,
            'message' => 'Overtime request reverted to pending successfully.',
            'data' => $reverted,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function responseRelations(): array
    {
        return [
            'employee:id,emp_id,firstname,middlename,lastname,department_id',
            'employee.department:id,department_name',
            'employee.employments.department:id,department_name',
            'employee.employments.position:id,position',
            'employee.latestEmployment',
            'employee.latestEmployment.position:id,position',
            'workSchedule:id,schedule_code,schedule_name,required_work_hours,grace_period,break_start,break_end,status',
            'workSchedule.days:id,work_schedule_id,day_of_week,is_working_day,time_in,time_out',
            'timeLog:id,employee_id,work_date,time_in,time_out,overtime_minutes,status',
            'approver:id,name,email',
        ];
    }

    private function appendCurrentPosition(OvertimeRequest $overtimeRequest): OvertimeRequest
    {
        $employee = $overtimeRequest->employee;
        $position = $employee?->latestEmployment?->position;

        if ($employee) {
            $employee->setAttribute('position_id', $position?->id);
            $employee->setAttribute('position', $position?->position);
            $employee->setAttribute('position_details', $position ? [
                'id' => $position->id,
                'position' => $position->position,
            ] : null);
        }

        return $overtimeRequest;
    }
}
