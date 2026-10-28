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
     */
    public function run(): void
    {
        $company = Company::where('company_name', 'R Work Central')->firstOrFail();
        $admin = User::where('email', 'hr@rworkcentral.test')->firstOrFail();

        $departments = [
            ['name' => 'Human Resources', 'code' => 'HR'],
            ['name' => 'Information Technology', 'code' => 'IT'],
            ['name' => 'Finance and Accounting', 'code' => 'FIN'],
            ['name' => 'Operations', 'code' => 'OPS'],
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
