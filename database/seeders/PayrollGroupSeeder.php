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
            'Regular Employees Group',
            'Project-Based Employees',
            'Management',
            'Drivers',
        ];

        foreach ($groups as $group) {
            PayrollGroup::firstOrCreate([
                'group_name' => $group,
            ]);
        }
    }
}
