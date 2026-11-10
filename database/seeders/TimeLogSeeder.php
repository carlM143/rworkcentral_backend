<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\TimeLog;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Seeder;

class TimeLogSeeder extends Seeder
{
    private const START_DATE = '2026-10-26';
    private const END_DATE = '2026-11-09';

    private const BREAK_START = '12:00';
    private const BREAK_END = '13:00';

    public function run(): void
    {
        $employees = Employee::query()
            ->where('status', true)
            ->with('latestEmployment.workSchedule.days')
            ->orderBy('id')
            ->get();

        if ($employees->isEmpty()) {
            $this->command?->warn('No active employees found. Run EmployeeSeeder first.');
            return;
        }

        $defaultSchedule = WorkSchedule::with('days')
            ->where('schedule_code', 'WS-001')
            ->first();

        $dates = collect(CarbonPeriod::create(self::START_DATE, self::END_DATE));

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($employees as $employeeIndex => $employee) {
            $schedule = $employee->latestEmployment?->workSchedule;

            if (!$schedule || $schedule->days->isEmpty()) {
                $schedule = $defaultSchedule;
            }

            if (!$schedule || $schedule->days->isEmpty()) {
                $this->command?->warn("Skipped {$employee->firstname} {$employee->lastname}: no work schedule days found.");
                continue;
            }
            $days = $schedule?->days->keyBy('day_of_week') ?? collect();
            $grace = (int) ($schedule?->grace_period ?? 0);

            foreach ($dates as $dayIndex => $date) {
                $day = $days->get($date->format('l'));

                if (!$day || !$day->is_working_day || !$day->time_in || !$day->time_out) {
                    continue;
                }

                $scenario = $this->scenario($employeeIndex, $dayIndex);

                if ($scenario === 'absent') {
                    $skipped++;
                    continue;
                }

                $log = $this->buildLog(
                    $date->toDateString(),
                    substr($day->time_in, 0, 5),
                    substr($day->time_out, 0, 5),
                    $grace,
                    $scenario,
                    $employeeIndex + $dayIndex
                );

                $record = TimeLog::updateOrCreate(
                    [
                        'employee_id' => $employee->id,
                        'work_date' => $date->toDateString(),
                    ],
                    [
                        ...$log,
                        'work_schedule_id' => $schedule?->id,
                        'grace_period_minutes' => $grace,
                        'overtime_minutes' => 0,
                        'source' => 'manual',
                        'remarks' => 'Sample attendance for payroll testing.',
                    ]
                );

                $record->wasRecentlyCreated ? $created++ : $updated++;
            }
        }

        $this->command?->info(
            "Time logs seeded: {$created} created, {$updated} updated, {$skipped} absences (no log)."
        );
    }

    private function scenario(int $employeeIndex, int $dayIndex): string
    {
        return match (($employeeIndex * 7 + $dayIndex * 3) % 20) {
            0, 11 => 'late',
            5 => 'undertime',
            8 => 'incomplete',
            14 => 'absent',
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
            default => $in->copy()->subMinutes($seed % 6),
        };

        $actualOut = match ($scenario) {
            'undertime' => $out->copy()->subMinutes(30),
            'incomplete' => null,
            default => $out->copy()->addMinutes($seed % 5),
        };

        $rawLate = max(0, (int) $in->diffInMinutes($actualIn, false));
        $chargeableLate = $rawLate > $grace ? $rawLate : 0;

        $undertime = $actualOut && $actualOut->lt($out)
            ? (int) $actualOut->diffInMinutes($out, true)
            : 0;

        $breakStart = Carbon::parse("{$date} " . self::BREAK_START);
        $breakEnd = Carbon::parse("{$date} " . self::BREAK_END);
        $hasBreak = $actualOut && $actualIn->lt($breakStart) && $actualOut->gt($breakEnd);

        $worked = $actualOut
            ? max(0, (int) $actualIn->diffInMinutes($actualOut, true) - ($hasBreak ? 60 : 0))
            : 0;

        $status = match (true) {
            $actualOut === null => 'Incomplete',
            $chargeableLate > 0 => 'Late',
            $undertime > 0 => 'Undertime',
            default => 'Complete',
        };

        return [
            'time_in' => $actualIn->toDateTimeString(),
            'time_out' => $actualOut?->toDateTimeString(),
            'break_out' => $hasBreak ? $breakStart->toDateTimeString() : null,
            'break_in' => $hasBreak ? $breakEnd->toDateTimeString() : null,
            'scheduled_time_in' => $in->toDateTimeString(),
            'scheduled_time_out' => $out->toDateTimeString(),
            'raw_late_minutes' => $rawLate,
            'chargeable_late_minutes' => $chargeableLate,
            'late_minutes' => $chargeableLate,
            'undertime_minutes' => $undertime,
            'worked_minutes' => $worked,
            'status' => $status,
        ];
    }
}