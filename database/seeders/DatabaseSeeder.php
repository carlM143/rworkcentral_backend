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
            RoleSeeder::class,
            TimekeepingPrivilegeSeeder::class,
            UserSeeder::class,
            CompanySeeder::class,
            ContributionTypeSeeder::class,
            DepartmentSeeder::class,
            PositionSeeder::class,

            ScheduleTypeSeeder::class,

            LocationSeeder::class,
            EmploymentTypeSeeder::class,
            EmploymentDocumentSeeder::class,

            SalaryTypeSeeder::class,

            PayrollGroupSeeder::class,
            PayrollPeriodSeeder::class,
            WorkScheduleSeeder::class,
            WorkScheduleDaySeeder::class,

            LeaveTypeSeeder::class,
            LeavePolicySeeder::class,

            LoanTypeSeeder::class,

            EmployeeSeeder::class,

            TimeLogSeeder::class,
        ]);
    }
}