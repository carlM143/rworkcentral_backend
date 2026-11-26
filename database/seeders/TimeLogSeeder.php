<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\TimeLog;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Seeder;

/**
 * Sample attendance for the RWEB employees of EmployeeSeeder
 * (emp_id 1001–1019), November 1–25, 2026.
 *
 * Re-running is safe: logs are matched by employee and date. Sample logs that
 * no longer belong (other employees, dates outside the range, no-work dates)
 * are removed so the data matches the client's employee list.
 */
class TimeLogSeeder extends Seeder
{
    private const START_DATE = '2026-11-01';
    private const END_DATE = '2026-11-25';

    /** Employees seeded by EmployeeSeeder (RWEB list). */
    private const FIRST_EMP_ID = 1001;
    private const LAST_EMP_ID = 1019;

    private const SAMPLE_REMARKS = 'Sample attendance for payroll testing.';

    /**
     * Dates nobody worked (non-working holidays), so no time logs are created.
     * Nov 1, 2026 — All Saints' Day (also a Sunday)
     * Nov 2, 2026 — All Souls' Day
     */
    private const NO_WORK_DATES = [
        '2026-11-01',
        '2026-11-02',
    ];

    /**
     * One sample undertime log: the first employee leaves early on this date.
     * Nov 10, 2026 is a Tuesday (working day).
     */
    private const UNDERTIME_DATE = '2026-11-10';
    private const UNDERTIME_MINUTES = 90;

    /**
     * One sample incomplete log: the second employee has a time in but no time out
     * on this date, to test the payroll "incomplete time logs" warning.
     */
    private const MISSING_TIME_OUT_DATE = '2026-11-10';

    private const BREAK_START = '12:00';
    private const BREAK_END = '13:00';

    public function run(): void
    {
        $employees = Employee::query()
            ->where('status', true)
            ->whereBetween('emp_id', [self::FIRST_EMP_ID, self::LAST_EMP_ID])
            ->with('latestEmployment.workSchedule.days')
            ->orderBy('emp_id')
            ->get();

        if ($employees->isEmpty()) {
            $this->command?->warn('No RWEB employees (emp_id 1001–1019) found. Run EmployeeSeeder first.');
            return;
        }

        $defaultSchedule = WorkSchedule::with('days')
            ->where('schedule_code', 'WS-001')
            ->first();

        $dates = collect(CarbonPeriod::create(self::START_DATE, '1 day', self::END_DATE))->values();

        // Sample logs that no longer belong: other employees, outside the range, or no-work dates
        $removed = TimeLog::query()
            ->where('remarks', self::SAMPLE_REMARKS)
            ->where(function ($q) use ($employees) {
                $q->whereNotIn('employee_id', $employees->pluck('id'))
                    ->orWhere('work_date', '<', self::START_DATE)
                    ->orWhere('work_date', '>', self::END_DATE)
                    ->orWhereIn('work_date', self::NO_WORK_DATES);
            })
            ->delete();

        $created = 0;
        $updated = 0;

        foreach ($employees as $employeeIndex => $employee) {
            $schedule = $employee->latestEmployment?->workSchedule;

            if (!$schedule || $schedule->days->isEmpty()) {
                $schedule = $defaultSchedule;
            }

            if (!$schedule || $schedule->days->isEmpty()) {
                $this->command?->warn("Skipped {$employee->firstname} {$employee->lastname}: no work schedule days found.");
                continue;
            }

            // No attendance before the employee was hired
            $hired = $employee->latestEmployment?->date_hired
                ? Carbon::parse($employee->latestEmployment->date_hired)->toDateString()
                : null;

            $days = $schedule->days->keyBy('day_of_week');
            $grace = (int) ($schedule->grace_period ?? 0);

            foreach ($dates as $dayIndex => $date) {
                $workDate = $date->toDateString();

                if (in_array($workDate, self::NO_WORK_DATES, true)) {
                    continue;
                }

                if ($hired && $workDate < $hired) {
                    continue;
                }

                $day = $days->get($date->format('l'));

                if (!$day || !$day->is_working_day || !$day->time_in || !$day->time_out) {
                    continue;
                }

                $log = $this->buildLog(
                    $workDate,
                    substr($day->time_in, 0, 5),
                    substr($day->time_out, 0, 5),
                    $grace,
                    match (true) {
                        $employeeIndex === 0 && $workDate === self::UNDERTIME_DATE => 'undertime',
                        $employeeIndex === 1 && $workDate === self::MISSING_TIME_OUT_DATE => 'missing_time_out',
                        default => $this->scenario($employeeIndex, $dayIndex),
                    },
                    $employeeIndex + $dayIndex
                );

                $record = TimeLog::updateOrCreate(
                    [
                        'employee_id' => $employee->id,
                        'work_date' => $workDate,
                    ],
                    [
                        ...$log,
                        'work_schedule_id' => $schedule->id,
                        'grace_period_minutes' => $grace,
                        'overtime_minutes' => 0,
                        'source' => 'manual',
                        'remarks' => self::SAMPLE_REMARKS,
                    ]
                );

                $record->wasRecentlyCreated ? $created++ : $updated++;
            }
        }

        $range = self::START_DATE . ' to ' . self::END_DATE;
        $skipped = implode(', ', self::NO_WORK_DATES);

        $this->command?->info(
            "Time logs seeded for {$employees->count()} employees ({$range}, no logs on {$skipped}): "
            . "{$created} created, {$updated} updated, {$removed} removed."
        );
    }

    private function scenario(int $employeeIndex, int $dayIndex): string
    {
        return match (($employeeIndex * 7 + $dayIndex * 3) % 20) {
            0, 6, 11 => 'late',
            17 => 'within_grace',
            default => 'complete',
        };
    }

    private function buildLog(
        string $date,
        string $scheduledIn,
        string $scheduledOut,
        int $grace,
        string $scenario,
        int $seed
    ): array {
        $in = Carbon::parse("{$date} {$scheduledIn}");
        $out = Carbon::parse("{$date} {$scheduledOut}");

        $actualIn = match ($scenario) {
            'late' => $in->copy()->addMinutes(10 + ($seed % 4) * 5),
            'within_grace' => $in->copy()->addMinutes(max(1, min($grace, 3))),
            'undertime', 'missing_time_out' => $in->copy(),
            default => $in->copy()->subMinutes($seed % 6),
        };

        // Time in only: no time out, no break, nothing worked, nothing to measure undertime against
        if ($scenario === 'missing_time_out') {
            return [
                'time_in' => $actualIn->toDateTimeString(),
                'time_out' => null,
                'break_out' => null,
                'break_in' => null,
                'scheduled_time_in' => $in->toDateTimeString(),
                'scheduled_time_out' => $out->toDateTimeString(),
                'raw_late_minutes' => 0,
                'chargeable_late_minutes' => 0,
                'late_minutes' => 0,
                'undertime_minutes' => 0,
                'worked_minutes' => 0,
                'status' => 'Incomplete',
            ];
        }

        $actualOut = $scenario === 'undertime'
            ? $out->copy()->subMinutes(self::UNDERTIME_MINUTES)
            : $out->copy()->addMinutes($seed % 5);

        // Minutes left before the scheduled time out (0 unless the employee left early)
        $undertime = $actualOut->lt($out) ? (int) $actualOut->diffInMinutes($out) : 0;

        $rawLate = max(0, (int) $in->diffInMinutes($actualIn, false));
        $chargeableLate = $rawLate > $grace ? $rawLate : 0;

        $breakStart = Carbon::parse("{$date} " . self::BREAK_START);
        $breakEnd = Carbon::parse("{$date} " . self::BREAK_END);
        $hasBreak = $actualIn->lt($breakStart) && $actualOut->gt($breakEnd);

        $worked = max(0, (int) $actualIn->diffInMinutes($actualOut, true) - ($hasBreak ? 60 : 0));

        return [
            'time_in' => $actualIn->toDateTimeString(),
            'time_out' => $actualOut->toDateTimeString(),
            'break_out' => $hasBreak ? $breakStart->toDateTimeString() : null,
            'break_in' => $hasBreak ? $breakEnd->toDateTimeString() : null,
            'scheduled_time_in' => $in->toDateTimeString(),
            'scheduled_time_out' => $out->toDateTimeString(),
            'raw_late_minutes' => $rawLate,
            'chargeable_late_minutes' => $chargeableLate,
            'late_minutes' => $chargeableLate,
            'undertime_minutes' => $undertime,
            'worked_minutes' => $worked,
            'status' => match (true) {
                $chargeableLate > 0 => 'Late',
                $undertime > 0 => 'Undertime',
                default => 'Complete',
            },
        ];
    }
}