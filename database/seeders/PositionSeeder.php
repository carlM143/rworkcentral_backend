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
            'RWS' => [
                ['name' => 'Web Development Manager', 'description' => 'Leads web development projects and coordinates the development team.'],
                ['name' => 'Web Developer', 'description' => 'Develops and maintains company websites and web applications.'],
                ['name' => 'Software Developer', 'description' => 'Designs, develops, and maintains software applications.'],
                ['name' => 'UI/UX Designer', 'description' => 'Designs user interfaces and improves the experience of web applications.'],
                ['name' => 'Project Coordinator', 'description' => 'Coordinates project schedules, requirements, and client deliverables.'],
            ],

            'AR' => [
                ['name' => 'Technical Operations Manager', 'description' => 'Oversees technical operations, field assignments, and service quality.'],
                ['name' => 'Lead Technician', 'description' => 'Leads technicians and handles complex installation and repair work.'],
                ['name' => 'Field Technician', 'description' => 'Performs on-site installation, maintenance, and troubleshooting.'],
                ['name' => 'IT Support Specialist', 'description' => 'Provides technical support and maintains computer systems.'],
                ['name' => 'Junior Technician', 'description' => 'Assists with technical installation, maintenance, and support tasks.'],
                ['name' => 'Driver', 'description' => 'Transports technicians, equipment, and materials to field sites and keeps the service vehicle in good condition.'],
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