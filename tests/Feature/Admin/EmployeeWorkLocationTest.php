<?php

namespace Tests\Feature\Admin;

use App\Models\Company;
use App\Models\Department;
use App\Models\EmploymentType;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeWorkLocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_work_location_is_saved_returned_updated_and_validated(): void
    {
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);
        $company = Company::create(['admin_id' => $admin->id, 'company_name' => 'Example']);
        $department = Department::create(['admin_id' => $admin->id, 'company_id' => $company->id, 'department_name' => 'Operations', 'department_code' => 'OPS', 'status' => true]);
        $position = Position::create(['department_id' => $department->id, 'position' => 'Staff', 'description' => 'Staff', 'status' => true]);
        $type = EmploymentType::create(['name' => 'Regular']);
        $main = $company->locations()->create(['location_name' => 'Main', 'city' => 'Manila']);
        $branch = $company->locations()->create(['location_name' => 'Branch', 'city' => 'Davao']);

        $payload = [
            'employee' => ['firstname' => 'Test', 'lastname' => 'Employee', 'birthday' => '1990-01-01', 'gender' => 'Male', 'civil_status' => 'Single'],
            'contact' => ['email' => 'employee@example.test', 'residential_address' => 'Manila'],
            'employment' => ['employment_type_id' => $type->id, 'department_id' => $department->id, 'position_id' => $position->id, 'date_hired' => '2026-01-01', 'location_id' => $main->id],
            'contact_persons' => [['name' => 'Contact', 'relationship' => 'Sibling', 'contact_number' => '09171234567', 'address' => 'Manila']],
        ];
        $id = $this->postJson('/api/admin/employees', $payload)->assertCreated()->json('data.id');
        $this->getJson("/api/admin/employees/{$id}")->assertOk()->assertJsonPath('data.employments.0.location.id', $main->id);
        $this->patchJson("/api/admin/employees/{$id}", ['employment' => ['location_id' => $branch->id]])
            ->assertOk()->assertJsonPath('data.employments.0.location.id', $branch->id);
        $this->patchJson("/api/admin/employees/{$id}", ['employee' => ['firstname' => 'Updated']])
            ->assertOk()->assertJsonPath('data.employments.0.location.id', $branch->id);
        $this->patchJson("/api/admin/employees/{$id}", ['employment' => ['location_id' => 999999]])
            ->assertUnprocessable()->assertJsonValidationErrors('employment.location_id');
        $other = Company::create(['admin_id' => $admin->id, 'company_name' => 'Other']);
        $foreign = $other->locations()->create(['location_name' => 'Other', 'city' => 'Cebu']);
        $this->patchJson("/api/admin/employees/{$id}", ['employment' => ['location_id' => $foreign->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('employment.location_id');
        $this->patchJson("/api/admin/employees/{$id}", ['employment' => ['location_id' => null]])
            ->assertOk()->assertJsonPath('data.employments.0.location', null);
    }
}
