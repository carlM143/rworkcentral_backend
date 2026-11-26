<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    /**
     * Seed the application's departments.
     *
     * Office/Department codes follow the client's RBDB list (RWS, OPR, FAD,
     * RWEB). Airpro (AR) is kept for the existing driver and demo seeders.
     */
    public function run(): void
    {
        $company = Company::where('company_name', 'R Work Central')->firstOrFail();
        $admin = User::where('email', 'hr@rworkcentral.test')->firstOrFail();

        $departments = [
            ['name' => 'R Web Solutions', 'code' => 'RWS'],
            ['name' => 'Operations', 'code' => 'OPR'],
            ['name' => 'Finance and Administration', 'code' => 'FAD'],
            ['name' => 'Executive Office', 'code' => 'RWEB'],
            ['name' => 'Airpro', 'code' => 'AR'],
        ];

        foreach ($departments as $department) {
            Department::updateOrCreate(
                [
                    'company_id' => $company->id,
                    'department_code' => $department['code'],
                ],
                [
                    'admin_id' => $admin->id,
                    'department_name' => $department['name'],
                    'status' => true,
                ],
            );
        }
    }
}