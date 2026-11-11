<?php

namespace App\Services;

use App\Models\Employment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

class AttendanceScheduleService
{
    public function resolve(
        int $employeeId,
        string $workDate
    ): ?array {
        $date = CarbonImmutable::parse($workDate)->startOfDay();

        $employment = Employment::query()
            ->with('workSchedule.days')
            ->where('employee_id', $employeeId)
            ->whereDate('date_hired', '<=', $date->toDateString())
            ->orderByDesc('date_hired')
            ->orderByDesc('id')
            ->first();

        if (!$employment || !$employment->workSchedule) {
            return null;
        }

        $schedule = $employment->workSchedule;

        // Prefer the per-day schedule row (work_schedule_days) for this weekday,
        // falling back to the schedule header times.
        $dayPrefix = strtolower($date->format('D'));
        $scheduleDay = $schedule->days?->first(
            fn ($d) => strtolower(substr(trim((string) $d->day_of_week), 0, 3)) === $dayPrefix
        );

        $timeIn = $scheduleDay?->time_in ?: $schedule->time_in;
        $timeOut = $scheduleDay?->time_out ?: $schedule->time_out;

        if (!$timeIn || !$timeOut) {
            return null;
        }

        $scheduledIn = CarbonImmutable::parse(
            $date->toDateString() . ' ' . $timeIn
        );

        $scheduledOut = CarbonImmutable::parse(
            $date->toDateString() . ' ' . $timeOut
        );

        // Support overnight shifts.
        if ($scheduledOut->lessThanOrEqualTo($scheduledIn)) {
            $scheduledOut = $scheduledOut->addDay();
        }

        $gracePeriod = max(
            0,
            (int) ($schedule->grace_period ?? 0)
        );

        return [
            'employment_id' => $employment->id,
            'employee_id' => $employeeId,
            'work_schedule_id' => $schedule->id,
            'schedule_name' => $schedule->schedule_name,
            'work_date' => $date->toDateString(),

            'scheduled_in' => $scheduledIn->toDateTimeString(),
            'scheduled_out' => $scheduledOut->toDateTimeString(),

            'grace_period_minutes' => $gracePeriod,
            'required_work_hours' => (float) (
                $schedule->required_work_hours ?? 0
            ),

            'break_start' => $schedule->break_start,
            'break_end' => $schedule->break_end,

            'restday_id' => $employment->restday_id,

            // Per-day and rest-day validation will be
            // integrated after their configuration is verified.
            'day_rule_verified' => false,
        ];
    }

    /**
     * Calculate raw and chargeable lateness.
     *
     * Policy:
     * chargeable = max(0, raw lateness - grace period)
     */
    public function calculateLate(
        int $employeeId,
        string $workDate,
        ?string $actualTimeIn
    ): ?array {
        $schedule = $this->resolve($employeeId, $workDate);

        if (!$schedule) {
            return null;
        }

        if (!$actualTimeIn) {
            return [
                ...$schedule,
                'actual_time_in' => null,
                'raw_late_minutes' => null,
                'grace_applied_minutes' => 0,
                'chargeable_late_minutes' => null,
            ];
        }

        $actual = CarbonImmutable::parse($actualTimeIn);

        $scheduled = CarbonImmutable::parse(
            $schedule['scheduled_in']
        );

        $rawLate = $actual->greaterThan($scheduled)
            ? (int) floor(
                $scheduled->diffInSeconds($actual) / 60
            )
            : 0;

        $graceApplied = min(
            $rawLate,
            $schedule['grace_period_minutes']
        );

        $chargeableLate = max(
            0,
            $rawLate - $graceApplied
        );

        return [
            ...$schedule,

            'actual_time_in' => $actual->toDateTimeString(),
            'raw_late_minutes' => $rawLate,
            'grace_applied_minutes' => $graceApplied,
            'chargeable_late_minutes' => $chargeableLate,
        ];
    }
}
