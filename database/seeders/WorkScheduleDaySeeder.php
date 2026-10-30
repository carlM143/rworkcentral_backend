<?php

namespace Database\Seeders;

use App\Models\WorkSchedule;
use App\Models\WorkScheduleDay;
use Illuminate\Database\Seeder;

class WorkScheduleDaySeeder extends Seeder
{
    public function run(): void
    {
        $schedule = WorkSchedule::where('schedule_code', 'WS-001')->firstOrFail();

        $days = [
            [
                'day_of_week' => 'Monday',
                'is_working_day' => true,
                'time_in' => '08:00:00',
                'time_out' => '16:00:00',
            ],
            [
                'day_of_week' => 'Tuesday',
                'is_working_day' => true,
                'time_in' => '08:00:00',
                'time_out' => '16:00:00',
            ],
            [
                'day_of_week' => 'Wednesday',
                'is_working_day' => true,
                'time_in' => '09:00:00',
                'time_out' => '17:00:00',
            ],
            [
                'day_of_week' => 'Thursday',
                'is_working_day' => true,
                'time_in' => '08:00:00',
                'time_out' => '16:00:00',
            ],
            [
                'day_of_week' => 'Friday',
                'is_working_day' => true,
                'time_in' => '08:00:00',
                'time_out' => '16:00:00',
            ],
            [
                'day_of_week' => 'Sat',
                'is_working_day' => true,
                'time_in' => '08:00:00',
                'time_out' => '12:00:00',
            ],
            [
                'day_of_week' => 'Sun',
                'is_working_day' => false,
                'time_in' => null,
                'time_out' => null,
            ],
        ];

        foreach ($days as $day) {
            WorkScheduleDay::updateOrCreate(
                [
                    'work_schedule_id' => $schedule->id,
                    'day_of_week' => $day['day_of_week'],
                ],
                [
                    'is_working_day' => $day['is_working_day'],
                    'time_in' => $day['time_in'],
                    'time_out' => $day['time_out'],
                ]
            );
        }
    }
}
