<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\UndertimeRequest;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UndertimeController extends Controller
{
    public function index(): JsonResponse
    {
        $requests = UndertimeRequest::query()
            ->with($this->responseRelations())
            ->latest('id')
            ->get();

        $requests->each(fn (UndertimeRequest $undertimeRequest) => $this->appendCurrentPosition($undertimeRequest));

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

            return $this->appendCurrentPosition(
                $record->fresh($this->responseRelations())
            );
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

            return $this->appendCurrentPosition(
                $record->fresh($this->responseRelations())
            );
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

        $start = Carbon::parse($validated['scheduled_time_out']);
        $end = Carbon::parse($validated['requested_time_out']);
        $minutes = max(0, (int) $start->diffInMinutes($end, false));

        $employee = Employee::findOrFail($validated['employee_id']);
        $scheduleId = $employee->employments()->latest('id')->first()?->work_schedule_id;

        if (! $scheduleId) {
            throw ValidationException::withMessages([
                'employee_id' => 'The selected employee does not have an assigned work schedule.',
            ]);
        }

        $requestNo = 'UT-'.date('Ymd').'-'.strtoupper(Str::random(6));
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

        $undertimeRequest = $this->appendCurrentPosition(
            $undertimeRequest->fresh($this->responseRelations())
        );

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
                    $fileName = 'req_'.time().'_'.Str::random(10).'.'.$extension;
                    $path = "requests/{$employeeId}/{$fileName}";
                    Storage::disk('public')->put($path, $binary);

                    return $path;
                }
            }
        }

        return null;
    }

    public function revert(Request $request, UndertimeRequest $undertimeRequest): JsonResponse
    {
        $reverted = DB::transaction(function () use ($undertimeRequest): UndertimeRequest {
            $record = UndertimeRequest::query()
                ->lockForUpdate()
                ->findOrFail($undertimeRequest->id);

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
            'message' => 'Undertime request reverted to pending successfully.',
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
            'timeLog:id,employee_id,work_date,time_in,time_out,undertime_minutes,status',
            'approver:id,name,email',
        ];
    }

    private function appendCurrentPosition(UndertimeRequest $undertimeRequest): UndertimeRequest
    {
        $employee = $undertimeRequest->employee;
        $position = $employee?->latestEmployment?->position;

        if ($employee) {
            $employee->setAttribute('position_id', $position?->id);
            $employee->setAttribute('position', $position?->position);
            $employee->setAttribute('position_details', $position ? [
                'id' => $position->id,
                'position' => $position->position,
            ] : null);
        }

        return $undertimeRequest;
    }
}
