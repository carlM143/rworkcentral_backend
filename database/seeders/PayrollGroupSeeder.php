<?php

namespace Database\Seeders;

use App\Models\PayrollGroup;
use Illuminate\Database\Seeder;

class PayrollGroupSeeder extends Seeder
{
    /**
     * Seed the application's payroll groups.
     */
    public function run(): void
    {
        $groups = [
            'Regular Employees',
            'Probationary Employees',
            'Contractual Employees',
            'Project-Based Employees',
            'Management',
            'Executive',
            'Drivers',
        ];

        foreach ($groups as $group) {
            PayrollGroup::firstOrCreate([
                'group_name' => $group,
            ]);
        }
    }
}
