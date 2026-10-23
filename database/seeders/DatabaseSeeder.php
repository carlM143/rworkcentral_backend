<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            RoleSeeder::class,
            CompanySeeder::class,
            DepartmentSeeder::class,
            PositionSeeder::class,

            ScheduleTypeSeeder::class,
            RestDaySeeder::class,

            EmploymentTypeSeeder::class,
            SalaryTypeSeeder::class,
            PayrollGroupSeeder::class,
            WorkScheduleSeeder::class,
        ]);
    }
}
