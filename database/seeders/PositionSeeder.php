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
     *
     * Positions follow the client's RBDB list. The older RWS and Airpro
     * positions are kept so existing records and seeders still resolve.
     */
    public function run(): void
    {
        $company = Company::where('company_name', 'R Work Central')->firstOrFail();

        $positions = [
            'RWEB' => [
                ['name' => 'General Manager', 'description' => 'Leads the company and oversees all departments and operations.'],
            ],

            'RWS' => [
                ['name' => 'Operations Supervisor', 'description' => 'Supervises daily operations, schedules and service quality of the team.'],
                ['name' => 'Senior Technical Lead', 'description' => 'Leads technical planning and guides developers on complex projects.'],
                ['name' => 'Junior Technical Lead', 'description' => 'Leads development tasks and supports the senior technical lead.'],
                ['name' => 'Asst. Supervisor - Web Dev', 'description' => 'Assists in supervising the web development team and its deliverables.'],
                ['name' => 'Asst. Graphic Designer Supervisor', 'description' => 'Assists in supervising graphic design work and designers.'],
                ['name' => 'Asst. Sales Supervisor', 'description' => 'Assists in supervising sales activities and client accounts.'],
                ['name' => 'Project Manager', 'description' => 'Plans and manages client projects, timelines and deliverables.'],
                ['name' => 'Full Stack Developer', 'description' => 'Builds and maintains both front-end and back-end parts of web applications.'],
                ['name' => 'Systems Developer', 'description' => 'Develops and maintains internal and client systems.'],
                ['name' => 'Web Developer', 'description' => 'Develops and maintains company websites and web applications.'],
                ['name' => 'UI/UX Designer', 'description' => 'Designs user interfaces and improves the experience of web applications.'],
                ['name' => 'Graphic Designer', 'description' => 'Creates visual designs for digital and print materials.'],
                ['name' => 'Content Writer', 'description' => 'Writes and edits web, marketing and social media content.'],
                // Older positions (kept for existing records)
                ['name' => 'Web Development Manager', 'description' => 'Leads web development projects and coordinates the development team.'],
                ['name' => 'Software Developer', 'description' => 'Designs, develops, and maintains software applications.'],
                ['name' => 'Project Coordinator', 'description' => 'Coordinates project schedules, requirements, and client deliverables.'],
            ],

            'OPR' => [
                ['name' => 'UI/UX & System Designer', 'description' => 'Designs user interfaces and system workflows for operations.'],
            ],

            'FAD' => [
                ['name' => 'Senior Accounting Staff', 'description' => 'Handles accounting records, payables, receivables and reports.'],
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