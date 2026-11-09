<?php

namespace App\Http\Controllers\Api\Employee;

use App\Http\Controllers\Controller;
use App\Models\AttendanceQrToken;
use App\Models\AttendanceScan;
use App\Models\Employee;
use App\Models\TimeLog;
use App\Services\AttendanceScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeAttendanceController extends Controller
{
    // Ignore a second scan this soon after time in (prevents accidental double scans)
    private const MIN_MINUTES_BEFORE_TIME_OUT = 1;

    public function __construct(private AttendanceScheduleService $scheduleService) {}

    // STORE ATTENDANCE FROM QR SCAN (first scan = time in, next scan = time out)
    // POST /api/employee/attendance/scan
    public function scan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'qr_payload' => ['required', 'string'],
        ]);

        $employee = $request->user()->employee;

        if (! $employee) {
            throw ValidationException::withMessages([
                'employee' => 'Your account is not linked to an employee record.',
            ]);
        }

        $now = now();

        // Decide time in / time out from the employee's open log
        $openLog = $this->findOpenLog($employee->id, $now);
        $action = $openLog ? AttendanceScan::ACTION_TIME_OUT : AttendanceScan::ACTION_TIME_IN;

        // Validate the scanned QR (session + token)
        $token = $this->resolveToken($data['qr_payload'], $request, $employee, $action, $now);

        try {
            $timeLog = $this->storeScan($employee, $token, $now, $request);
        } catch (ValidationException $e) {
            // Rejections inside the transaction were rolled back, so log them here
            $this->reject($request, $employee, $action, $now, collect($e->errors())->flatten()->first(), $token);
        }

        $isTimeOut = (bool) $timeLog->time_out;

        return response()->json([
            'success' => true,
            'message' => $isTimeOut ? 'Time out recorded successfully.' : 'Time in recorded successfully.',
            'data'    => [
                'action'                  => $isTimeOut ? AttendanceScan::ACTION_TIME_OUT : AttendanceScan::ACTION_TIME_IN,
                'time_log_id'             => $timeLog->id,
                'work_date'               => $timeLog->work_date?->toDateString(),
                'time_in'                 => $timeLog->time_in?->toDateTimeString(),
                'time_out'                => $timeLog->time_out?->toDateTimeString(),
                'scheduled_time_in'       => $timeLog->scheduled_time_in?->toDateTimeString(),
                'scheduled_time_out'      => $timeLog->scheduled_time_out?->toDateTimeString(),
                'late_minutes'            => $timeLog->late_minutes,
                'chargeable_late_minutes' => $timeLog->chargeable_late_minutes,
                'undertime_minutes'       => $timeLog->undertime_minutes,
                'worked_minutes'          => $timeLog->worked_minutes,
                'status'                  => $timeLog->status,
            ],
        ], 201);
    }

    private function storeScan(Employee $employee, AttendanceQrToken $token, Carbon $now, Request $request): TimeLog
    {
        return DB::transaction(function () use ($employee, $token, $now, $request) {
            // Re-read under lock so two quick scans can't both time in
            $openLog = $this->findOpenLog($employee->id, $now, lock: true);

            $log = $openLog
                ? $this->recordTimeOut($openLog, $now)
                : $this->recordTimeIn($employee, $now);

            AttendanceScan::create([
                'employee_id'              => $employee->id,
                'attendance_qr_session_id' => $token->attendance_qr_session_id,
                'attendance_qr_token_id'   => $token->id,
                'time_log_id'              => $log->id,
                'action'                   => $log->time_out ? AttendanceScan::ACTION_TIME_OUT : AttendanceScan::ACTION_TIME_IN,
                'result'                   => AttendanceScan::RESULT_ACCEPTED,
                'scanned_at'               => $now,
                'device_info'              => substr((string) $request->userAgent(), 0, 255) ?: null,
                'ip_address'               => $request->ip(),
            ]);

            return $log;
        });
    }

    // Latest log from today or yesterday (overnight shifts) that has time in but no time out
    private function findOpenLog(int $employeeId, Carbon $now, bool $lock = false): ?TimeLog
    {
        $query = TimeLog::query()
            ->where('employee_id', $employeeId)
            ->whereDate('work_date', '>=', $now->copy()->subDay()->toDateString())
            ->whereNotNull('time_in')
            ->whereNull('time_out')
            ->orderByDesc('work_date')
            ->orderByDesc('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function resolveToken(string $payload, Request $request, Employee $employee, string $action, Carbon $now): AttendanceQrToken
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded) || empty($decoded['s']) || empty($decoded['t']) || ! is_string($decoded['t'])) {
            $this->reject($request, $employee, $action, $now, 'Invalid QR code.');
        }

        $token = AttendanceQrToken::query()
            ->with('session')
            ->where('token_hash', hash('sha256', $decoded['t']))
            ->first();

        if (! $token || (int) $token->attendance_qr_session_id !== (int) $decoded['s']) {
            $this->reject($request, $employee, $action, $now, 'Invalid QR code.');
        }

        $session = $token->session;

        if ($session->status !== 'active' || $session->revoked_at !== null || $token->revoked_at !== null) {
            $this->reject($request, $employee, $action, $now, 'This QR code has been revoked.', $token);
        }

        if ($now->lt($session->starts_at) || $now->gt($session->ends_at)) {
            $this->reject($request, $employee, $action, $now, 'This QR code is outside its active time window.', $token);
        }

        if ($now->gt($token->expires_at)) {
            $this->reject($request, $employee, $action, $now, 'This QR code has expired. Please scan the latest code.', $token);
        }

        return $token;
    }

    // Log the rejected scan, then return a validation error
    private function reject(Request $request, Employee $employee, string $action, Carbon $now, string $reason, ?AttendanceQrToken $token = null): never
    {
        AttendanceScan::create([
            'employee_id'              => $employee->id,
            'attendance_qr_session_id' => $token?->attendance_qr_session_id,
            'attendance_qr_token_id'   => $token?->id,
            'action'                   => $action,
            'result'                   => AttendanceScan::RESULT_REJECTED,
            'scanned_at'               => $now,
            'rejection_reason'         => $reason,
            'device_info'              => substr((string) $request->userAgent(), 0, 255) ?: null,
            'ip_address'               => $request->ip(),
        ]);

        throw ValidationException::withMessages(['qr_payload' => $reason]);
    }

    // Thrown inside the transaction; scan() logs it as a rejected scan
    private function fail(string $reason): never
    {
        throw ValidationException::withMessages(['qr_payload' => $reason]);
    }

    private function recordTimeIn(Employee $employee, Carbon $now): TimeLog
    {
        $workDate = $now->toDateString();

        $existing = TimeLog::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $workDate)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            if (in_array(strtolower((string) $existing->status), ['on leave', 'leave', 'rest day'], true)) {
                $this->fail('Attendance today is marked as Leave or Rest Day.');
            }

            if ($existing->time_in && $existing->time_out) {
                $this->fail('You have already timed in and out today.');
            }
        }

        $schedule    = $this->scheduleService->resolve($employee->id, $workDate);
        $calculation = $this->scheduleService->calculateLate($employee->id, $workDate, $now->toDateTimeString());

        $rawLate        = (int) ($calculation['raw_late_minutes'] ?? 0);
        $chargeableLate = (int) ($calculation['chargeable_late_minutes'] ?? 0);

        $values = [
            'work_schedule_id'        => $schedule['work_schedule_id'] ?? null,
            'scheduled_time_in'       => $schedule['scheduled_in'] ?? null,
            'scheduled_time_out'      => $schedule['scheduled_out'] ?? null,
            'time_in'                 => $now->toDateTimeString(),
            'late_minutes'            => $rawLate,
            'raw_late_minutes'        => $rawLate,
            'grace_period_minutes'    => (int) ($calculation['grace_period_minutes'] ?? 0),
            'chargeable_late_minutes' => $chargeableLate,
            'source'                  => 'qr',
            'status'                  => 'Incomplete',
        ];

        if ($existing) {
            $existing->update($values);

            return $existing->fresh();
        }

        return TimeLog::create(['employee_id' => $employee->id, 'work_date' => $workDate] + $values);
    }

    private function recordTimeOut(TimeLog $log, Carbon $now): TimeLog
    {
        $timeIn = Carbon::parse($log->time_in);

        if ($timeIn->diffInMinutes($now) < self::MIN_MINUTES_BEFORE_TIME_OUT) {
            $this->fail('You just timed in. Please wait before timing out.');
        }

        $undertime = 0;

        if ($log->scheduled_time_out && $now->lt($log->scheduled_time_out)) {
            $undertime = (int) floor($now->diffInSeconds($log->scheduled_time_out) / 60);
        }

        $workedMinutes = (int) floor($timeIn->diffInSeconds($now) / 60);

        if ($log->break_out && $log->break_in) {
            $workedMinutes -= (int) floor(Carbon::parse($log->break_out)->diffInSeconds(Carbon::parse($log->break_in)) / 60);
        }

        if ((int) $log->chargeable_late_minutes > 0) {
            $status = 'Late';
        } elseif ($undertime > 0) {
            $status = 'Undertime';
        } else {
            $status = 'Complete';
        }

        $log->update([
            'time_out'          => $now->toDateTimeString(),
            'undertime_minutes' => $undertime,
            'worked_minutes'    => max(0, $workedMinutes),
            'status'            => $status,
        ]);

        return $log->fresh();
    }
}
