<?php

namespace Tests\Feature\Admin;

use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DepartmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_a_department_with_a_code(): void
    {
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);

        $company = Company::create([
            'admin_id' => $admin->id,
            'company_name' => 'Test Company',
            'location' => 'Manila',
            'status' => true,
        ]);

        $response = $this->postJson('/api/admin/departments', [
            'company_id' => $company->id,
            'department_name' => 'Human Resources',
            'department_code' => 'HR',
            'status' => true,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.department_name', 'Human Resources')
            ->assertJsonPath('data.department_code', 'HR');

        $this->assertDatabaseHas('departments', [
            'admin_id' => $admin->id,
            'company_id' => $company->id,
            'department_name' => 'Human Resources',
            'department_code' => 'HR',
        ]);
    }

    public function test_authenticated_user_can_update_a_department_code(): void
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

        $response = $this->patchJson("/api/admin/departments/{$department->id}", [
            'department_code' => 'ICT',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.department_code', 'ICT');

        $this->assertDatabaseHas('departments', [
            'id' => $department->id,
            'department_code' => 'ICT',
        ]);
    }

    public function test_department_code_must_be_unique_within_a_company(): void
    {
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);

        $company = Company::create([
            'admin_id' => $admin->id,
            'company_name' => 'Test Company',
            'location' => 'Manila',
            'status' => true,
        ]);

        Department::create([
            'admin_id' => $admin->id,
            'company_id' => $company->id,
            'department_name' => 'Human Resources',
            'department_code' => 'HR',
            'status' => true,
        ]);

        $response = $this->postJson('/api/admin/departments', [
            'company_id' => $company->id,
            'department_name' => 'People Operations',
            'department_code' => 'HR',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('department_code');
    }
}
