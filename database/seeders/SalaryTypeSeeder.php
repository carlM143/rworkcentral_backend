<?php

namespace Database\Seeders;

use App\Models\SalaryType;
use Illuminate\Database\Seeder;

class SalaryTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Monthly','Semi-Monthly','Daily', 'Hourly', ] as $type) {
            SalaryType::firstOrCreate(['type_name' => $type]);
        }
    }
}