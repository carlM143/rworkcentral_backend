<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\TimeLog;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class TimeLogSeeder extends Seeder
{
    public function run(): void
    {
        $employees = Employee::query()
            ->orderBy('id')
            ->limit(3)
            ->get();

        if ($employees->count() < 3) {
            $this->command?->warn(
                'At least 3 employees are required for this sample seeder.'
            );

            return;
        }

        $dates = [
            '2026-09-01',
            '2026-09-02',
            '2026-09-03',
            '2026-09-04',
            '2026-09-05',
        ];

        $samples = [
            [
                ['07:55', '17:02', 'Complete'],
                ['08:08', '17:00', 'Late'],
                ['07:58', '17:05', 'Complete'],
                ['07:54', '17:03', 'Complete'],
                ['08:00', null, 'Incomplete'],
            ],
            [
                ['08:12', '17:00', 'Late'],
                ['07:52', '17:04', 'Complete'],
                ['08:00', '16:35', 'Undertime'],
                ['07:56', '17:00', 'Complete'],
                ['07:59', '17:05', 'Complete'],
            ],
            [
                [null, null, 'Missing'],
                ['07:51', '17:02', 'Complete'],
                ['07:55', '17:08', 'Complete'],
                ['08:15', '17:00', 'Late'],
                ['07:57', '17:03', 'Complete'],
            ],
        ];

        $created = 0;
        $existing = 0;

        foreach ($employees as $employeeIndex => $employee) {
            foreach ($dates as $dayIndex => $date) {
                [$timeIn, $timeOut, $status] =
                    $samples[$employeeIndex][$dayIndex];

                $breakOut = $timeIn && $timeOut
                    ? '12:00'
                    : null;

                $breakIn = $timeIn && $timeOut
                    ? '13:00'
                    : null;

                $lateMinutes = 0;
                $undertimeMinutes = 0;
                $workedMinutes = 0;

                // -------------------------------------
                // LATE MINUTES
                // -------------------------------------

                if ($timeIn && $timeIn > '08:00') {
                    $lateMinutes = (int) Carbon::parse('08:00')
                        ->diffInMinutes(
                            Carbon::parse($timeIn),
                            true
                        );
                }

                // -------------------------------------
                // UNDERTIME MINUTES
                // -------------------------------------

                if ($timeOut && $timeOut < '17:00') {
                    $undertimeMinutes = (int) Carbon::parse($timeOut)
                        ->diffInMinutes(
                            Carbon::parse('17:00'),
                            true
                        );
                }

                // -------------------------------------
                // WORKED MINUTES
                // -------------------------------------

                if ($timeIn && $timeOut) {
                    $start = Carbon::parse("$date $timeIn");
                    $end = Carbon::parse("$date $timeOut");

                    $workedMinutes = max(
                        0,
                        (int) $start->diffInMinutes($end, true) - 60
                    );
                }

                // -------------------------------------
                // DATETIME FORMAT
                // -------------------------------------

                $dateTime = function (?string $time) use ($date) {
                    return $time
                        ? "$date $time:00"
                        : null;
                };

                // -------------------------------------
                // INSERT DUMMY ATTENDANCE
                // -------------------------------------

                $log = TimeLog::firstOrCreate(
                    [
                        'employee_id' => $employee->id,
                        'work_date' => $date,
                    ],
                    [
                        'time_in' => $dateTime($timeIn),
                        'time_out' => $dateTime($timeOut),
                        'break_out' => $dateTime($breakOut),
                        'break_in' => $dateTime($breakIn),
                        'late_minutes' => $lateMinutes,
                        'undertime_minutes' => $undertimeMinutes,
                        'worked_minutes' => $workedMinutes,
                        'overtime_minutes' => 0,
                        'source' => 'manual',
                        'remarks' => 'Dummy attendance record for testing.',
                        'status' => $status,
                    ]
                );

                if ($log->wasRecentlyCreated) {
                    $created++;
                } else {
                    $existing++;
                }
            }
        }

        $this->command?->info(
            "Time Log Seeder completed: {$created} created, {$existing} already exist."
        );
    }
}
