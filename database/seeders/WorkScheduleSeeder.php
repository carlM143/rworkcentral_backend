<?php

namespace Database\Seeders;

use App\Models\ScheduleType;
use App\Models\WorkSchedule;
use Illuminate\Database\Seeder;

class WorkScheduleSeeder extends Seeder
{
    /**
     * Seed the application's work schedules.
     */
    public function run(): void
    {
        $scheduleType = ScheduleType::where('type_name', 'Regular')->firstOrFail();

        WorkSchedule::updateOrCreate(
            [
                'schedule_code' => 'WS-001',
            ],
            [
                'schedule_name' => 'Regular',
                'schedule_type_id' => $scheduleType->id,
                'time_in' => '08:00:00',
                'time_out' => '18:00:00',
                'required_work_hours' => 10,
                'grace_period' => 5,
                'break_start' => '12:00:00',
                'break_end' => '13:00:00',
                'status' => true,
            ]
        );
    }
}
