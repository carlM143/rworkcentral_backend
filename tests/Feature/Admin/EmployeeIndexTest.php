<?php

namespace Tests\Feature\Admin;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_fetch_employees_with_related_data(): void
    {
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);

        $company = Company::create([
            'admin_id' => $admin->id,
            'company_name' => 'Test Company',
            'location' => 'Manila',
            'status' => true,
        ]);

        $department = Department::create([
            'admin_id' => $admin->id,
            'company_id' => $company->id,
            'department_name' => 'Information Technology',
            'department_code' => 'IT',
            'status' => true,
        ]);

        $role = Role::create(['name' => 'Employee']);
        $employeeUser = User::factory()->create(['role_id' => $role->id]);

        $employee = Employee::create([
            'user_id' => $employeeUser->id,
            'department_id' => $department->id,
            'emp_id' => 12345678,
            'firstname' => 'Juan',
            'lastname' => 'Dela Cruz',
            'age' => 30,
            'gender' => 'Male',
            'birthday' => '1996-01-01',
            'civil_status' => 'Single',
            'status' => true,
        ]);

        $employee->contactInformation()->create([
            'email' => 'juan@example.com',
            'mobile' => '09171234567',
            'residential_address' => 'Manila',
        ]);

        $response = $this->getJson('/api/admin/employees?search=juan@example.com');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Employees retrieved successfully.')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $employee->id)
            ->assertJsonPath('data.0.department.department_code', 'IT')
            ->assertJsonPath('data.0.user.role.name', 'Employee')
            ->assertJsonPath('data.0.contact_information.email', 'juan@example.com')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'department',
                        'user',
                        'contact_information',
                        'employments',
                    ],
                ],
            ]);
    }

    public function test_employee_index_requires_authentication(): void
    {
        $this->getJson('/api/admin/employees')
            ->assertUnauthorized();
    }
}
