<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TimeLog;
use App\Services\AttendanceScheduleService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManualTimeEntryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeHR($request);

        $logs = TimeLog::query()
            ->with('employee')
            ->where('source', 'manual')
            ->orderByDesc('work_date')
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $logs->map(
                fn (TimeLog $log) => $this->formatLog($log)
            )->values(),
        ]);
    }

    public function show(
        Request $request,
        TimeLog $timeLog
    ): JsonResponse {
        $this->authorizeHR($request);

        abort_unless($timeLog->source === 'manual', 404);

        return response()->json([
            'success' => true,
            'data' => $this->formatLog(
                $timeLog->load('employee')
            ),
        ]);
    }

    public function store(
        Request $request,
        AttendanceScheduleService $scheduleService
    ): JsonResponse {
        $this->authorizeHR($request);

        $data = $request->validate([
            'employee_id' => [
                'required',
                'integer',
                'exists:employees,id',
            ],
            'work_date' => [
                'required',
                'date_format:Y-m-d',
                'before_or_equal:today',
            ],
            'time_in' => [
                'nullable',
                'date_format:H:i',
                'required_without:time_out',
            ],
            'time_out' => [
                'nullable',
                'date_format:H:i',
                'required_without:time_in',
            ],
            'remarks' => [
                'nullable',
                'string',
                'max:2000',
            ],
            // Supporting file storage requires its own
            // persistent attachment reference.
            'attachment' => ['prohibited'],
        ]);

        $employeeId = (int) $data['employee_id'];
        $workDate = $data['work_date'];

        $schedule = $scheduleService->resolve(
            $employeeId,
            $workDate
        );

        if (!$schedule) {
            throw ValidationException::withMessages([
                'work_date' =>
                    'No assigned work schedule was found for this date.',
            ]);
        }

        $scheduledIn = Carbon::parse(
            $schedule['scheduled_in']
        );

        $scheduledOut = Carbon::parse(
            $schedule['scheduled_out']
        );

        // Same-day attendance for the initial implementation.
        if ($scheduledOut->toDateString() !== $workDate) {
            throw ValidationException::withMessages([
                'work_date' =>
                    'Overnight shifts are not supported by this form yet.',
            ]);
        }

        $submittedIn = $this->parseTime(
            $workDate,
            $data['time_in'] ?? null
        );

        $submittedOut = $this->parseTime(
            $workDate,
            $data['time_out'] ?? null
        );

        $log = DB::transaction(function () use (
            $request,
            $scheduleService,
            $data,
            $employeeId,
            $workDate,
            $schedule,
            $scheduledIn,
            $scheduledOut,
            $submittedIn,
            $submittedOut
        ) {
            $existing = TimeLog::query()
                ->where('employee_id', $employeeId)
                ->whereDate('work_date', $workDate)
                ->lockForUpdate()
                ->first();

            // Never silently overwrite recorded punches.
            if ($existing) {
                if ($submittedIn && $existing->time_in) {
                    throw ValidationException::withMessages([
                        'time_in' =>
                            'Time In is already recorded. Use Time Log Correction instead.',
                    ]);
                }

                if ($submittedOut && $existing->time_out) {
                    throw ValidationException::withMessages([
                        'time_out' =>
                            'Time Out is already recorded. Use Time Log Correction instead.',
                    ]);
                }

                if (in_array(
                    strtolower((string) $existing->status),
                    ['on leave', 'leave', 'rest day'],
                    true
                )) {
                    throw ValidationException::withMessages([
                        'work_date' =>
                            'Attendance is marked as Leave or Rest Day.',
                    ]);
                }

                if (
                    $existing->correctionRequests()
                        ->whereIn('status', ['pending', 'approved'])
                        ->exists()
                ) {
                    throw ValidationException::withMessages([
                        'work_date' =>
                            'This attendance has a correction request. Resolve it first.',
                    ]);
                }

                if (
                    $existing->lateAdjustments()
                        ->whereIn('status', ['pending', 'approved'])
                        ->exists()
                ) {
                    throw ValidationException::withMessages([
                        'work_date' =>
                            'This attendance has a late adjustment. Resolve it before making manual changes.',
                    ]);
                }
            }

            $timeIn = $submittedIn
                ?: ($existing?->time_in
                    ? Carbon::parse($existing->time_in)
                    : null);

            $timeOut = $submittedOut
                ?: ($existing?->time_out
                    ? Carbon::parse($existing->time_out)
                    : null);

            if ($timeIn && $timeOut && $timeOut->lte($timeIn)) {
                throw ValidationException::withMessages([
                    'time_out' =>
                        'Time Out must be later than Time In.',
                ]);
            }

            $breakOut = $existing?->break_out
                ? Carbon::parse($existing->break_out)
                : null;

            $breakIn = $existing?->break_in
                ? Carbon::parse($existing->break_in)
                : null;

            if ($breakOut || $breakIn) {
                if (
                    !$breakOut ||
                    !$breakIn ||
                    ($timeIn && $breakOut->lt($timeIn)) ||
                    ($timeOut && $breakIn->gt($timeOut)) ||
                    $breakIn->lte($breakOut)
                ) {
                    throw ValidationException::withMessages([
                        'time_out' =>
                            'Manual time conflicts with existing break records.',
                    ]);
                }
            }

            $calculation = $scheduleService->calculateLate(
                $employeeId,
                $workDate,
                $timeIn?->toDateTimeString()
            );

            $rawLate = (int) (
                $calculation['raw_late_minutes'] ?? 0
            );

            $grace = (int) (
                $calculation['grace_period_minutes'] ?? 0
            );

            $chargeableLate = (int) (
                $calculation['chargeable_late_minutes'] ?? 0
            );

            $undertime = 0;
            $workedMinutes = 0;

            if ($timeOut && $timeOut->lt($scheduledOut)) {
                $undertime = (int) floor(
                    $timeOut->diffInSeconds($scheduledOut) / 60
                );
            }

            if ($timeIn && $timeOut) {
                $workedMinutes = (int) floor(
                    $timeIn->diffInSeconds($timeOut) / 60
                );

                if ($breakOut && $breakIn) {
                    $workedMinutes -= (int) floor(
                        $breakOut->diffInSeconds($breakIn) / 60
                    );
                }

                $workedMinutes = max(0, $workedMinutes);
            }

            if (!$timeIn && !$timeOut) {
                $status = 'Missing';
            } elseif (!$timeIn || !$timeOut) {
                $status = 'Incomplete';
            } elseif ($chargeableLate > 0) {
                $status = 'Late';
            } elseif ($undertime > 0) {
                $status = 'Undertime';
            } else {
                $status = 'Complete';
            }

            $newRemarks = trim(
                (string) ($data['remarks'] ?? '')
            );

            $auditNote = sprintf(
                '[Manual attendance recorded by user ID %d]',
                $request->user()->id
            );

            $remarks = collect([
                $existing?->remarks,
                $auditNote,
                $newRemarks,
            ])
                ->filter(fn ($value) => filled($value))
                ->implode("\n");

            $values = [
                'work_schedule_id' =>
                    $schedule['work_schedule_id'],
                'scheduled_time_in' =>
                    $scheduledIn->toDateTimeString(),
                'scheduled_time_out' =>
                    $scheduledOut->toDateTimeString(),
                'time_in' =>
                    $timeIn?->toDateTimeString(),
                'time_out' =>
                    $timeOut?->toDateTimeString(),
                'late_minutes' => $rawLate,
                'raw_late_minutes' => $rawLate,
                'grace_period_minutes' => $grace,
                'chargeable_late_minutes' =>
                    $chargeableLate,
                'undertime_minutes' => $undertime,
                'worked_minutes' => $workedMinutes,
                'overtime_minutes' =>
                    (int) ($existing?->overtime_minutes ?? 0),
                'source' => 'manual',
                'remarks' => $remarks,
                'status' => $status,
            ];

            if ($existing) {
                $existing->update($values);
                return $existing->fresh('employee');
            }

            return TimeLog::create(array_merge(
                [
                    'employee_id' => $employeeId,
                    'work_date' => $workDate,
                ],
                $values
            ))->load('employee');
        });

        return response()->json([
            'success' => true,
            'message' =>
                'Manual attendance saved successfully.',
            'data' => $this->formatLog($log),
        ], 201);
    }

    public function update(
    Request $request,
    TimeLog $timeLog,
    AttendanceScheduleService $scheduleService
): JsonResponse {
    $this->authorizeHR($request);

    $data = $request->validate([
        'time_in' => [
            'present',
            'nullable',
            'date_format:H:i',
        ],
        'time_out' => [
            'present',
            'nullable',
            'date_format:H:i',
        ],
        'remarks' => [
            'nullable',
            'string',
            'max:2000',
        ],
        'employee_id' => ['prohibited'],
        'work_date' => ['prohibited'],
        'attachment' => ['prohibited'],
    ]);

    if (empty($data['time_in']) && empty($data['time_out'])) {
        throw ValidationException::withMessages([
            'time_in' => 'Provide at least Time In or Time Out.',
        ]);
    }

    $updated = DB::transaction(function () use (
        $request,
        $timeLog,
        $scheduleService,
        $data
    ) {
        $log = TimeLog::query()
            ->whereKey($timeLog->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if ($log->source !== 'manual') {
            throw ValidationException::withMessages([
                'time_in' =>
                    'Only manually recorded attendance can be edited.',
            ]);
        }

        if (
            $log->correctionRequests()
                ->whereIn('status', ['pending', 'approved'])
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'time_in' =>
                    'This attendance has a pending or approved correction request.',
            ]);
        }

        if (
            $log->lateAdjustments()
                ->whereIn('status', ['pending', 'approved'])
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'time_in' =>
                    'This attendance has a pending or approved late adjustment.',
            ]);
        }

        if (in_array(
            strtolower((string) $log->status),
            ['on leave', 'leave', 'rest day'],
            true
        )) {
            throw ValidationException::withMessages([
                'time_in' =>
                    'Attendance marked as Leave or Rest Day cannot be edited.',
            ]);
        }

        $workDate = $log->work_date->format('Y-m-d');
        $employeeId = (int) $log->employee_id;

        $schedule = $scheduleService->resolve(
            $employeeId,
            $workDate
        );

        if (!$schedule) {
            throw ValidationException::withMessages([
                'time_in' =>
                    'No assigned work schedule was found for this date.',
            ]);
        }

        $scheduledIn = Carbon::parse($schedule['scheduled_in']);
        $scheduledOut = Carbon::parse($schedule['scheduled_out']);

        if ($scheduledOut->toDateString() !== $workDate) {
            throw ValidationException::withMessages([
                'time_in' =>
                    'Overnight shifts are not supported by this form yet.',
            ]);
        }

        $timeIn = $this->parseTime(
            $workDate,
            $data['time_in'] ?? null
        );

        $timeOut = $this->parseTime(
            $workDate,
            $data['time_out'] ?? null
        );

        if ($timeIn && $timeOut && $timeOut->lte($timeIn)) {
            throw ValidationException::withMessages([
                'time_out' => 'Time Out must be later than Time In.',
            ]);
        }

        $breakOut = $log->break_out
            ? Carbon::parse($log->break_out)
            : null;

        $breakIn = $log->break_in
            ? Carbon::parse($log->break_in)
            : null;

        if ($breakOut || $breakIn) {
            if (
                !$breakOut ||
                !$breakIn ||
                !$timeIn ||
                !$timeOut ||
                $breakOut->lt($timeIn) ||
                $breakIn->gt($timeOut) ||
                $breakIn->lte($breakOut)
            ) {
                throw ValidationException::withMessages([
                    'time_out' =>
                        'Attendance times conflict with existing break records.',
                ]);
            }
        }

        $calculation = $scheduleService->calculateLate(
            $employeeId,
            $workDate,
            $timeIn?->toDateTimeString()
        );

        $rawLate = (int) (
            $calculation['raw_late_minutes'] ?? 0
        );

        $grace = (int) (
            $calculation['grace_period_minutes'] ?? 0
        );

        $chargeableLate = (int) (
            $calculation['chargeable_late_minutes'] ?? 0
        );

        $undertime = 0;

        if ($timeOut && $timeOut->lt($scheduledOut)) {
            $undertime = (int) floor(
                $timeOut->diffInSeconds($scheduledOut) / 60
            );
        }

        $workedMinutes = 0;

        if ($timeIn && $timeOut) {
            $workedMinutes = (int) floor(
                $timeIn->diffInSeconds($timeOut) / 60
            );

            if ($breakOut && $breakIn) {
                $workedMinutes -= (int) floor(
                    $breakOut->diffInSeconds($breakIn) / 60
                );
            }

            $workedMinutes = max(0, $workedMinutes);
        }

        if (!$timeIn || !$timeOut) {
            $status = 'Incomplete';
        } elseif ($chargeableLate > 0) {
            $status = 'Late';
        } elseif ($undertime > 0) {
            $status = 'Undertime';
        } else {
            $status = 'Complete';
        }

        $newRemarks = trim((string) ($data['remarks'] ?? ''));

        $auditNote = sprintf(
            '[Manual attendance updated by user ID %d at %s]',
            $request->user()->id,
            now()->toDateTimeString()
        );

        $remarks = collect([
            $log->remarks,
            $auditNote,
            $newRemarks,
        ])
            ->filter(fn ($value) => filled($value))
            ->implode("\n");

        $log->update([
            'work_schedule_id' => $schedule['work_schedule_id'],
            'scheduled_time_in' => $scheduledIn->toDateTimeString(),
            'scheduled_time_out' => $scheduledOut->toDateTimeString(),
            'time_in' => $timeIn?->toDateTimeString(),
            'time_out' => $timeOut?->toDateTimeString(),
            'late_minutes' => $rawLate,
            'raw_late_minutes' => $rawLate,
            'grace_period_minutes' => $grace,
            'chargeable_late_minutes' => $chargeableLate,
            'undertime_minutes' => $undertime,
            'worked_minutes' => $workedMinutes,
            'source' => 'manual',
            'remarks' => $remarks,
            'status' => $status,
        ]);

        return $log->fresh('employee');
    });

    return response()->json([
        'success' => true,
        'message' => 'Manual attendance updated successfully.',
        'data' => $this->formatLog($updated),
    ]);
}

    private function parseTime(
        string $date,
        ?string $time
    ): ?Carbon {
        if (!$time) {
            return null;
        }

        return Carbon::createFromFormat(
            '!Y-m-d H:i',
            "{$date} {$time}"
        );
    }

    private function formatLog(TimeLog $log): array
    {
        $employee = $log->employee;

        return [
            'id' => $log->id,
            'employee_id' => $log->employee_id,
            'employee_number' => $employee?->emp_id,
            'employee_name' => trim(
                ($employee?->firstname ?? '') . ' ' .
                ($employee?->lastname ?? '')
            ),
            'work_date' =>
                $log->work_date?->format('Y-m-d'),
            'time_in' =>
                $log->time_in?->format('H:i'),
            'time_out' =>
                $log->time_out?->format('H:i'),
            'scheduled_time_in' =>
                $log->scheduled_time_in?->format('H:i'),
            'scheduled_time_out' =>
                $log->scheduled_time_out?->format('H:i'),
            'raw_late_minutes' =>
                (int) ($log->raw_late_minutes ?? 0),
            'grace_period_minutes' =>
                (int) ($log->grace_period_minutes ?? 0),
            'chargeable_late_minutes' =>
                (int) ($log->chargeable_late_minutes ?? 0),
            'undertime_minutes' =>
                (int) $log->undertime_minutes,
            'worked_minutes' =>
                (int) ($log->worked_minutes ?? 0),
            'source' => $log->source,
            'remarks' => $log->remarks,
            'status' => $log->status,
            'created_at' =>
                $log->created_at?->toDateTimeString(),
            'updated_at' =>
                $log->updated_at?->toDateTimeString(),
        ];
    }

    private function authorizeHR(Request $request): void
    {
        $user = $request->user();

        abort_unless(
            $user &&
            in_array(
                $user->role?->name,
                ['Administrator', 'Human Resources'],
                true
            ),
            403,
            'You are not authorized to manage manual attendance.'
        );
    }
}
