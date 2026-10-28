<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ScheduleType;

class ScheduleTypeSeeder extends Seeder
{
    public function run(): void
    {
        $scheduleTypes = [
            'Regular',
            'Morning',
            'Afternoon',
            'Night',
        ];

        foreach ($scheduleTypes as $type) {
            ScheduleType::firstOrCreate([
                'type_name' => $type,
            ]);
        }
    }
}