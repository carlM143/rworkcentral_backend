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
    private const END_DATE = '2026-11-10';

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

        $dates = collect(CarbonPeriod::create(self::START_DATE, '1 day', self::END_DATE))->values();

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

            $days = $schedule->days->keyBy('day_of_week');
            $grace = (int) ($schedule->grace_period ?? 0);

            foreach ($dates as $dayIndex => $date) {
                $day = $days->get($date->format('l'));

                if (!$day || !$day->is_working_day || !$day->time_in || !$day->time_out) {
                    continue;
                }

                $log = $this->buildLog(
                    $date->toDateString(),
                    substr($day->time_in, 0, 5),
                    substr($day->time_out, 0, 5),
                    $grace,
                    $this->scenario($employeeIndex, $dayIndex),
                    $employeeIndex + $dayIndex
                );

                $record = TimeLog::updateOrCreate(
                    [
                        'employee_id' => $employee->id,
                        'work_date' => $date->toDateString(),
                    ],
                    [
                        ...$log,
                        'work_schedule_id' => $schedule->id,
                        'grace_period_minutes' => $grace,
                        'overtime_minutes' => 0,
                        'source' => 'manual',
                        'remarks' => 'Sample attendance for payroll testing.',
                    ]
                );

                $record->wasRecentlyCreated ? $created++ : $updated++;
            }
        }

        $range = self::START_DATE . ' to ' . self::END_DATE;

        $this->command?->info("Time logs seeded ({$range}): {$created} created, {$updated} updated.");
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
            default => $in->copy()->subMinutes($seed % 6),
        };

        $actualOut = $out->copy()->addMinutes($seed % 5);

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
            'undertime_minutes' => 0,
            'worked_minutes' => $worked,
            'status' => $chargeableLate > 0 ? 'Late' : 'Complete',
        ];
    }
}