<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Employment;
use App\Models\TimeLog;
use Illuminate\Database\Seeder;

class EmployeeTimeLogCorrectionSeeder extends Seeder
{
    public function run(): void
    {
        // Employees created by EmployeeSeeder
        $employees = Employee::query()
            ->whereIn('emp_id', [1001, 1002, 1003, 1004])
            ->orderBy('emp_id')
            ->get();

        // Three attendance records per employee
        $samples = [
            [
                'work_date' => '2026-10-30',
                'time_in' => '07:58:00',
                'time_out' => '17:02:00',
                'break_out' => '12:00:00',
                'break_in' => '13:00:00',
                'scheduled_time_in' => '08:00:00',
                'scheduled_time_out' => '17:00:00',
                'worked_minutes' => 484,
                'status' => 'Complete',
            ],
            [
                'work_date' => '2026-11-02',
                'time_in' => '09:00:00',
                'time_out' => '17:00:00',
                'break_out' => '12:00:00',
                'break_in' => '13:00:00',
                'scheduled_time_in' => '09:00:00',
                'scheduled_time_out' => '17:00:00',
                'worked_minutes' => 420,
                'status' => 'Complete',
            ],
            [
                'work_date' => '2026-11-03',
                'time_in' => null, // Missing Time In
                'time_out' => '17:00:00',
                'break_out' => '12:00:00',
                'break_in' => '13:00:00',
                'scheduled_time_in' => '08:00:00',
                'scheduled_time_out' => '17:00:00',
                'worked_minutes' => 0,
                'status' => 'Incomplete',
            ],
        ];

        $created = 0;
        $existing = 0;

        $dateTime = static function (
            string $date,
            ?string $time
        ): ?string {
            return $time === null
                ? null
                : "{$date} {$time}";
        };

        foreach ($employees as $employee) {

            // Get employee's assigned work schedule
            $workScheduleId = Employment::query()
                ->where('employee_id', $employee->id)
                ->value('work_schedule_id');

            foreach ($samples as $sample) {

                $date = $sample['work_date'];

                $timeLog = TimeLog::firstOrCreate(
                    [
                        'employee_id' => $employee->id,
                        'work_date' => $date,
                    ],
                    [
                        'work_schedule_id' => $workScheduleId,

                        'time_in' => $dateTime(
                            $date,
                            $sample['time_in']
                        ),

                        'time_out' => $dateTime(
                            $date,
                            $sample['time_out']
                        ),

                        'break_out' => $dateTime(
                            $date,
                            $sample['break_out']
                        ),

                        'break_in' => $dateTime(
                            $date,
                            $sample['break_in']
                        ),

                        'scheduled_time_in' => $dateTime(
                            $date,
                            $sample['scheduled_time_in']
                        ),

                        'scheduled_time_out' => $dateTime(
                            $date,
                            $sample['scheduled_time_out']
                        ),

                        'worked_minutes' => $sample['worked_minutes'],
                        'late_minutes' => 0,
                        'undertime_minutes' => 0,
                        'overtime_minutes' => 0,
                        'raw_late_minutes' => 0,
                        'grace_period_minutes' => 0,
                        'chargeable_late_minutes' => 0,

                        'status' => $sample['status'],

                        'remarks' => $sample['time_in'] === null
                            ? 'Test record: Missing Time In for correction request.'
                            : 'Sample employee attendance record.',
                    ]
                );

                if ($timeLog->wasRecentlyCreated) {
                    $created++;
                } else {
                    $existing++;
                }
            }
        }

        $this->command?->info(
            "Time log seeding completed. Created: {$created}, Existing: {$existing}."
        );

        if ($employees->count() < 4) {
            $this->command?->warn(
                'Some test employees were not found. Run EmployeeSeeder first.'
            );
        }
    }
}
