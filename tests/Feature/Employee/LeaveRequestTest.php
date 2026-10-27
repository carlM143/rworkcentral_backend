<?php

namespace Tests\Feature\Employee;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentType;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeaveRequestTest extends TestCase
{
    use RefreshDatabase;

    private function setupData(): array
    {
        $admin = User::factory()->create();
        $company = Company::create([
            'admin_id' => $admin->id,
            'company_name' => 'Acme Corp',
            'location' => 'HQ',
            'status' => true,
        ]);

        $role = Role::create(['name' => 'Employee']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $department = Department::create([
            'admin_id' => $admin->id,
            'company_id' => $company->id,
            'department_name' => 'Operations',
            'department_code' => 'OPS',
            'status' => true,
        ]);

        $employee = Employee::create([
            'user_id' => $user->id,
            'department_id' => $department->id,
            'emp_id' => 10002,
            'firstname' => 'Pedro',
            'lastname' => 'Penduko',
            'age' => 25,
            'gender' => 'Male',
            'birthday' => '2001-01-01',
            'civil_status' => 'Single',
            'status' => true,
        ]);

        $employmentType = EmploymentType::create([
            'name' => 'Regular',
            'status' => 'active',
        ]);

        $leaveType = LeaveType::create([
            'user_id' => $user->id,
            'leave_code' => 'VL',
            'leave_name' => 'Vacation Leave',
            'status' => 'active',
        ]);

        $leavePolicy = LeavePolicy::create([
            'leave_type_id' => $leaveType->id,
            'employment_type_id' => $employmentType->id,
            'is_eligible' => true,
            'is_paid' => true,
            'annual_credits' => 12,
            'min_service_months' => 6,
            'allow_carry_over' => false,
            'max_carry_over_days' => 0,
            'requires_approval' => true,
            'usage_window' => 'calendar_year',
            'validity_period' => 'annual',
            'effective_from' => now()->startOfYear()->toDateString(),
            'status' => 'approved',
        ]);

        return [$user, $employee, $leaveType, $leavePolicy];
    }

    public function test_employee_can_fetch_leave_types(): void
    {
        [$user, , $leaveType] = $this->setupData();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/employee/leave-types');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.leave_code', 'VL');
    }

    public function test_employee_can_submit_leave_request(): void
    {
        [$user, $employee, $leaveType, $leavePolicy] = $this->setupData();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/employee/leave-requests', [
            'leave_type_id' => $leaveType->id,
            'leave_policy_id' => $leavePolicy->id,
            'request_date' => now()->addDays(5)->toDateString(),
            'reason' => 'Family vacation',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.reason', 'Family vacation');

        $this->assertDatabaseHas('leave_requests', [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'status' => 'pending',
        ]);
    }

    public function test_employee_can_cancel_pending_leave_request(): void
    {
        [$user, $employee, $leaveType, $leavePolicy] = $this->setupData();
        Sanctum::actingAs($user);

        $leaveRequest = $employee->leaveRequests()->create([
            'leave_type_id' => $leaveType->id,
            'leave_policy_id' => $leavePolicy->id,
            'request_no' => 'LR-TEST-001',
            'request_date' => now()->addDays(3)->toDateString(),
            'reason' => 'Personal matter',
            'status' => 'pending',
        ]);

        $response = $this->patchJson("/api/employee/leave-requests/{$leaveRequest->id}/cancel");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertEquals('cancelled', $leaveRequest->fresh()->status);
    }

    public function test_employee_can_list_leave_requests(): void
    {
        [$user, $employee, $leaveType, $leavePolicy] = $this->setupData();
        Sanctum::actingAs($user);

        $employee->leaveRequests()->create([
            'leave_type_id' => $leaveType->id,
            'leave_policy_id' => $leavePolicy->id,
            'request_no' => 'LR-TEST-002',
            'request_date' => now()->toDateString(),
            'reason' => 'Annual leave',
            'status' => 'pending',
        ]);

        $response = $this->getJson('/api/employee/leave-requests');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'Vacation Leave');
    }

    public function test_employee_can_update_pending_leave_request(): void
    {
        [$user, $employee, $leaveType, $leavePolicy] = $this->setupData();
        Sanctum::actingAs($user);

        $leaveRequest = $employee->leaveRequests()->create([
            'leave_type_id' => $leaveType->id,
            'leave_policy_id' => $leavePolicy->id,
            'request_no' => 'LR-TEST-003',
            'request_date' => now()->addDays(2)->toDateString(),
            'reason' => 'Initial reason',
            'status' => 'pending',
        ]);

        $response = $this->putJson("/api/employee/leave-requests/{$leaveRequest->id}", [
            'reason' => 'Updated reason for vacation',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.reason', 'Updated reason for vacation');

        $this->assertEquals('Updated reason for vacation', $leaveRequest->fresh()->reason);
    }
}
