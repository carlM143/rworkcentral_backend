<?php

namespace Tests\Feature\Employee;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentType;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeProfileLocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_returns_current_employment_location_or_null(): void
    {
        $admin = User::factory()->create();
        $user = User::factory()->create();
        $company = Company::create(['admin_id' => $admin->id, 'company_name' => 'Example']);
        $department = Department::create(['admin_id' => $admin->id, 'company_id' => $company->id, 'department_name' => 'Operations', 'department_code' => 'OPS', 'status' => true]);
        $position = Position::create(['department_id' => $department->id, 'position' => 'Staff', 'description' => 'Staff', 'status' => true]);
        $type = EmploymentType::create(['name' => 'Regular']);
        $location = $company->locations()->create(['location_name' => 'Branch', 'city' => 'Davao', 'province' => 'Davao del Sur']);
        $employee = Employee::create(['user_id' => $user->id, 'department_id' => $department->id, 'emp_id' => 12345678, 'firstname' => 'Test', 'lastname' => 'Employee', 'birthday' => '1990-01-01', 'status' => true]);
        $fields = ['employment_type_id' => $type->id, 'department_id' => $department->id, 'position_id' => $position->id];
        $employee->employments()->create([...$fields, 'date_hired' => '2020-01-01']);
        $current = $employee->employments()->create([...$fields, 'date_hired' => '2026-01-01', 'location_id' => $location->id]);
        Sanctum::actingAs($user);
        $this->getJson('/api/employee/profile')->assertOk()
            ->assertJsonPath('data.current_employment.location.location_name', 'Branch')
            ->assertJsonPath('data.current_employment.location.city', 'Davao')
            ->assertJsonPath('data.current_employment.location.province', 'Davao del Sur');
        $current->update(['location_id' => null]);
        $this->getJson('/api/employee/profile')->assertOk()->assertJsonPath('data.current_employment.location', null);
    }
}
