<?php

namespace Database\Seeders;

use App\Models\RestDay;
use Illuminate\Database\Seeder;

class RestDaySeeder extends Seeder
{
    /**
     * Seed the available rest days.
     */
    public function run(): void
    {
        $restDays = [
            'Monday',
            'Tuesday',
            'Wednesday',
            'Thursday',
            'Friday',
            'Saturday',
            'Sunday',
        ];

        foreach ($restDays as $restDay) {
            RestDay::updateOrCreate([
                'rest_day' => $restDay,
            ]);
        }
    }
}
