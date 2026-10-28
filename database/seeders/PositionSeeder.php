<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Department;
use App\Models\Position;
use Illuminate\Database\Seeder;

class PositionSeeder extends Seeder
{
    /**
     * Seed the application's positions.
     */
    public function run(): void
    {
        $company = Company::where('company_name', 'R Work Central')->firstOrFail();

        $positions = [
            'HR' => [
                ['name' => 'HR Manager', 'description' => 'Manages human resources operations, employee relations, and HR policies.'],
                ['name' => 'HR Officer', 'description' => 'Handles employee records, recruitment, and HR administration.'],
                ['name' => 'Recruitment Specialist', 'description' => 'Manages employee recruitment, screening, and hiring processes.'],
                ['name' => 'HR Assistant', 'description' => 'Provides administrative support for daily HR activities.'],
            ],

            'IT' => [
                ['name' => 'IT Manager', 'description' => 'Oversees IT operations, infrastructure, and technology projects.'],
                ['name' => 'Web Developer', 'description' => 'Develops and maintains company websites and web applications.'],
                ['name' => 'Software Developer', 'description' => 'Designs, develops, and maintains software applications.'],
                ['name' => 'IT Support Specialist', 'description' => 'Provides technical support and maintains computer systems.'],
                ['name' => 'System Administrator', 'description' => 'Manages servers, networks, and system infrastructure.'],
            ],

            'FIN' => [
                ['name' => 'Finance Manager', 'description' => 'Oversees financial operations, budgeting, and financial reporting.'],
                ['name' => 'Accountant', 'description' => 'Prepares financial statements and maintains accounting records.'],
                ['name' => 'Payroll Officer', 'description' => 'Processes employee payroll, salary adjustments, and deductions.'],
                ['name' => 'Accounting Assistant', 'description' => 'Assists in bookkeeping, reconciliation, and financial documentation.'],
            ],

            'OPS' => [
                ['name' => 'Operations Manager', 'description' => 'Manages daily operations and ensures efficient business processes.'],
                ['name' => 'Operations Supervisor', 'description' => 'Supervises operational activities and monitors employee performance.'],
                ['name' => 'Operations Coordinator', 'description' => 'Coordinates daily work assignments and operational schedules.'],
                ['name' => 'Administrative Staff', 'description' => 'Supports administrative and operational activities.'],
            ],
        ];

        foreach ($positions as $departmentCode => $departmentPositions) {
            $department = Department::where('company_id', $company->id)
                ->where('department_code', $departmentCode)
                ->firstOrFail();

            foreach ($departmentPositions as $position) {
                Position::updateOrCreate(
                    [
                        'department_id' => $department->id,
                        'position' => $position['name'],
                    ],
                    [
                        'description' => $position['description'],
                        'status' => true,
                    ]
                );
            }
        }
    }
}
