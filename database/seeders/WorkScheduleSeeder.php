<?php

namespace Database\Seeders;

use App\Models\ScheduleType;
use App\Models\WorkSchedule;
use Illuminate\Database\Seeder;

class WorkScheduleSeeder extends Seeder
{
    public function run(): void
    {
        $scheduleType = ScheduleType::where('type_name', 'Regular')->firstOrFail();

        $schedules = [
            ['code' => 'WS-001', 'name' => 'Rweb Solutions Corp.'],
            ['code' => 'WS-002', 'name' => 'Airpro Schedule'],
        ];

        foreach ($schedules as $schedule) {
            WorkSchedule::updateOrCreate(
                ['schedule_code' => $schedule['code']],
                [
                    'schedule_name' => $schedule['name'],
                    'schedule_type_id' => $scheduleType->id,
                    'required_work_hours' => 8,
                    'grace_period' => 5,
                    'break_start' => null,
                    'break_end' => null,
                    'status' => true,
                ]
            );
        }
    }
}