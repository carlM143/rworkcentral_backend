<?php

namespace App\Http\Controllers\Api\Employee;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\TimeLog;
use App\Models\TimeLogCorrectionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TimeLogCorrectionRequestController extends Controller
{
    private const TYPES = [
        'time_in',
        'time_out',
    ];

    /**
     * Get the employee linked to the authenticated user.
     */
    private function currentEmployee(Request $request): Employee
    {
        $employee = Employee::query()
            ->where(
                'user_id',
                $request->user()->getAuthIdentifier()
            )
            ->first();

        abort_unless(
            $employee,
            403,
            'No employee profile is linked to this account.'
        );

        return $employee;
    }

    /**
     * GET /api/employee/time-log-correction-requests/time-log
     *
     * Fetch the employee's recorded Time In and Time Out
     * for the selected attendance date.
     */
    public function timeLogByDate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => [
                'required',
                'date_format:Y-m-d',
            ],
        ]);

        $employee = $this->currentEmployee($request);

        $log = TimeLog::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $data['date'])
            ->first();

        if (!$log) {
            return response()->json([
                'found' => false,
                'message' => 'No attendance record exists for the selected date.',
                'data' => null,
            ]);
        }

        return response()->json([
            'found' => true,
            'data' => [
                'id' => $log->id,
                'work_date' => $log->work_date?->format('Y-m-d'),
                'time_in' => $this->clock($log->time_in),
                'time_out' => $this->clock($log->time_out),
                'status' => $log->status,
            ],
        ]);
    }

    /**
     * GET /api/employee/time-log-correction-requests
     *
     * List correction requests of the logged-in employee.
     */
    public function index(Request $request): JsonResponse
    {
        $employee = $this->currentEmployee($request);

        $records = TimeLogCorrectionRequest::query()
            ->with('timeLog')
            ->where('employee_id', $employee->id)
            ->latest('id')
            ->get();

        return response()->json([
            'data' => $records->map(
                fn (TimeLogCorrectionRequest $record) =>
                    $this->serialize($record)
            ),
        ]);
    }

    /**
     * GET /api/employee/time-log-correction-requests/{id}
     *
     * View one correction request.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $record = $this->ownedRequest($request, $id);

        return response()->json([
            'data' => $this->serialize($record),
        ]);
    }

    /**
     * POST /api/employee/time-log-correction-requests
     *
     * Submit a new correction request.
     */
    public function store(Request $request): JsonResponse
    {
        $employee = $this->currentEmployee($request);

        $data = $request->validate([
            'work_date' => [
                'required',
                'date_format:Y-m-d',
                'before_or_equal:today',
            ],

            'correction_type' => [
                'required',
                Rule::in(self::TYPES),
            ],

            'requested_time' => [
                'required',
                'date_format:H:i',
            ],

            'reason' => [
                'required',
                'string',
                'min:5',
                'max:2000',
            ],
        ]);

        $record = DB::transaction(function () use (
            $employee,
            $request,
            $data
        ) {
            // Retrieve and lock the employee's attendance record.
            $log = TimeLog::query()
                ->where('employee_id', $employee->id)
                ->whereDate('work_date', $data['work_date'])
                ->lockForUpdate()
                ->first();

            if (!$log) {
                throw ValidationException::withMessages([
                    'work_date' => [
                        'Select a date with an existing attendance record.',
                    ],
                ]);
            }

            // Prevent duplicate pending corrections for this date.
            $pending = TimeLogCorrectionRequest::query()
                ->where('employee_id', $employee->id)
                ->where('time_log_id', $log->id)
                ->where('status', 'pending')
                ->exists();

            if ($pending) {
                throw ValidationException::withMessages([
                    'work_date' => [
                        'You already have a pending correction for this date.',
                    ],
                ]);
            }

            // Original attendance is captured from the database.
            // Employees cannot overwrite the original recorded values.
            return TimeLogCorrectionRequest::create([
                'time_log_id' => $log->id,
                'employee_id' => $employee->id,
                'requested_by' => $request->user()->getAuthIdentifier(),

                'correction_type' => $data['correction_type'],
                'priority' => 'normal',
                'status' => 'pending',

                'original_time_in' => $log->time_in,
                'original_time_out' => $log->time_out,

                'requested_time' => $data['requested_time'] . ':00',

                'reason' => trim($data['reason']),
            ]);
        });

        return response()->json([
            'message' => 'Time log correction submitted for HR review.',
            'data' => $this->serialize(
                $record->load('timeLog')
            ),
        ], 201);
    }

    /**
     * PUT/PATCH /api/employee/time-log-correction-requests/{id}
     *
     * Update an existing pending correction request.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'correction_type' => [
                'required',
                Rule::in(self::TYPES),
            ],

            'requested_time' => [
                'required',
                'date_format:H:i',
            ],

            'reason' => [
                'required',
                'string',
                'min:5',
                'max:2000',
            ],
        ]);

        $record = DB::transaction(function () use (
            $request,
            $id,
            $data
        ) {
            $employee = $this->currentEmployee($request);

            $record = TimeLogCorrectionRequest::query()
                ->where('employee_id', $employee->id)
                ->lockForUpdate()
                ->findOrFail($id);

            abort_unless(
                $record->status === 'pending',
                422,
                'Only pending correction requests may be edited.'
            );

            $record->update([
                'correction_type' => $data['correction_type'],
                'requested_time' => $data['requested_time'] . ':00',
                'reason' => trim($data['reason']),
            ]);

            return $record;
        });

        return response()->json([
            'message' => 'Correction request updated successfully.',
            'data' => $this->serialize(
                $record->fresh('timeLog')
            ),
        ]);
    }

    /**
     * PATCH /api/employee/time-log-correction-requests/{id}/cancel
     *
     * Cancel an existing pending correction request.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        DB::transaction(function () use ($request, $id) {
            $employee = $this->currentEmployee($request);

            $record = TimeLogCorrectionRequest::query()
                ->where('employee_id', $employee->id)
                ->lockForUpdate()
                ->findOrFail($id);

            abort_unless(
                $record->status === 'pending',
                422,
                'Only pending correction requests may be cancelled.'
            );

            $record->update([
                'status' => 'cancelled',
            ]);
        });

        return response()->json([
            'message' => 'Correction request cancelled successfully.',
        ]);
    }

    /**
     * Retrieve a correction request owned by the employee.
     */
    private function ownedRequest(
        Request $request,
        int $id
    ): TimeLogCorrectionRequest {
        $employee = $this->currentEmployee($request);

        return TimeLogCorrectionRequest::query()
            ->with('timeLog')
            ->where('employee_id', $employee->id)
            ->findOrFail($id);
    }

    /**
     * Convert datetime or time values to HH:mm.
     */
    private function clock($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i');
        }

        return substr((string) $value, 0, 5);
    }

    /**
     * Format correction request data for frontend responses.
     */
    private function serialize(
        TimeLogCorrectionRequest $record
    ): array {
        return [
            'id' => $record->id,
            'time_log_id' => $record->time_log_id,

            'work_date' => $record->timeLog?->work_date
                ?->format('Y-m-d'),

            'correction_type' => $record->correction_type,
            'priority' => $record->priority,
            'status' => $record->status,

            'original_time_in' => $this->clock(
                $record->original_time_in
            ),

            'original_time_out' => $this->clock(
                $record->original_time_out
            ),

            'requested_time' => $this->clock(
                $record->requested_time
            ),

            'reason' => $record->reason,
            'attachment_path' => $record->attachment_path,

            'review_remarks' => $record->review_remarks,

            'reviewed_at' => $record->reviewed_at
                ?->toIso8601String(),

            'created_at' => $record->created_at
                ?->toIso8601String(),
        ];
    }
}
