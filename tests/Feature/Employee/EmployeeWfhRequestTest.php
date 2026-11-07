<?php

namespace Tests\Feature\Employee;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeWfhRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_request_is_owned_visible_to_admin_and_reflects_decision(): void
    {
        $admin = User::factory()->create();
        $company = Company::create(['admin_id' => $admin->id, 'company_name' => 'Example']);
        $department = Department::create(['admin_id' => $admin->id, 'company_id' => $company->id, 'department_name' => 'Operations', 'department_code' => 'OPS', 'status' => true]);
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $employee = Employee::create(['user_id' => $user->id, 'department_id' => $department->id, 'emp_id' => 12345678, 'firstname' => 'First', 'lastname' => 'Employee', 'birthday' => '1990-01-01', 'status' => true]);
        $other = Employee::create(['user_id' => $otherUser->id, 'department_id' => $department->id, 'emp_id' => 87654321, 'firstname' => 'Other', 'lastname' => 'Employee', 'birthday' => '1990-01-01', 'status' => true]);
        Sanctum::actingAs($user);
        $payload = ['employee_id' => $other->id, 'start_date' => '2026-11-09', 'end_date' => '2026-11-10', 'supervisor_name' => 'Supervisor', 'reason' => 'Home work', 'request_date' => '2000-01-01'];
        $this->postJson('/api/employee/wfh-requests/preview', $payload)->assertOk()->assertJsonPath('data.number_of_days', 2);
        $id = $this->postJson('/api/employee/wfh-requests', $payload)->assertCreated()->assertJsonPath('data.employee.id', $employee->id)->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->assertDatabaseHas('work_from_home', ['id' => $id, 'employee_id' => $employee->id]);
        $this->assertSame(now()->toDateString(), \App\Models\WorkFromHome::findOrFail($id)->request_date->toDateString());
        $this->postJson('/api/employee/wfh-requests', $payload)->assertUnprocessable()->assertJsonValidationErrors('start_date');
        Sanctum::actingAs($otherUser);
        $this->getJson('/api/employee/wfh-requests')->assertOk()->assertJsonCount(0, 'data');
        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/wfh-requests')->assertOk()->assertJsonFragment(['request_no' => 'WFH-2026-0001']);
        $this->patchJson("/api/admin/wfh-requests/{$id}/approve", [])->assertOk()->assertJsonPath('data.status', 'approved');
        Sanctum::actingAs($user);
        $this->getJson('/api/employee/wfh-requests')->assertOk()->assertJsonPath('data.0.status', 'approved');
    }

    public function test_authentication_and_employee_profile_are_required(): void
    {
        $this->getJson('/api/employee/wfh-requests')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/employee/wfh-requests')->assertNotFound();
        $this->postJson('/api/employee/wfh-requests', [])->assertNotFound();
    }
}
