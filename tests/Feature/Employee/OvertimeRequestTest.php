<?php

namespace Tests\Feature\Employee;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\ScheduleType;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OvertimeRequestTest extends TestCase
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
            'emp_id' => 10003,
            'firstname' => 'Maria',
            'lastname' => 'Clara',
            'age' => 24,
            'gender' => 'Female',
            'birthday' => '2002-03-20',
            'civil_status' => 'Single',
            'status' => true,
        ]);

        $scheduleType = ScheduleType::firstOrCreate([
            'type_name' => 'Standard',
        ]);

        $workSchedule = WorkSchedule::firstOrCreate(
            ['schedule_code' => 'DS-8-5'],
            [
                'schedule_type_id' => $scheduleType->id,
                'schedule_name' => 'Day Shift 8-5',
                'time_in' => '08:00:00',
                'time_out' => '17:00:00',
                'required_work_hours' => 8.00,
                'status' => true,
            ]
        );

        return [$user, $employee, $workSchedule];
    }

    public function test_employee_can_submit_overtime_request(): void
    {
        [$user, $employee, $workSchedule] = $this->setupData();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/employee/overtime-requests', [
            'ot_date' => now()->toDateString(),
            'start_at' => now()->setTime(17, 0, 0)->toDateTimeString(),
            'end_at' => now()->setTime(20, 0, 0)->toDateTimeString(),
            'reason' => 'Inventory reconciliation',
            'work_schedule_id' => $workSchedule->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('overtime_requests', [
            'employee_id' => $employee->id,
            'status' => 'pending',
        ]);
    }

    public function test_employee_can_cancel_pending_overtime_request(): void
    {
        [$user, $employee, $workSchedule] = $this->setupData();
        Sanctum::actingAs($user);

        $ot = $employee->overtimeRequests()->create([
            'work_schedule_id' => $workSchedule->id,
            'request_no' => 'OT-TEST-001',
            'ot_date' => now()->toDateString(),
            'start_at' => now()->setTime(17, 0, 0),
            'end_at' => now()->setTime(19, 0, 0),
            'reason' => 'Extra project tasks',
            'status' => 'pending',
        ]);

        $response = $this->patchJson("/api/employee/overtime-requests/{$ot->id}/cancel");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertEquals('cancelled', $ot->fresh()->status);
    }

    public function test_employee_can_list_overtime_requests(): void
    {
        [$user, $employee, $workSchedule] = $this->setupData();
        Sanctum::actingAs($user);

        $employee->overtimeRequests()->create([
            'work_schedule_id' => $workSchedule->id,
            'request_no' => 'OT-TEST-002',
            'ot_date' => now()->toDateString(),
            'start_at' => now()->setTime(17, 0, 0),
            'end_at' => now()->setTime(20, 0, 0),
            'reason' => 'Inventory count',
            'status' => 'pending',
        ]);

        $response = $this->getJson('/api/employee/overtime-requests');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'Overtime');
    }

    public function test_employee_can_update_pending_overtime_request(): void
    {
        [$user, $employee, $workSchedule] = $this->setupData();
        Sanctum::actingAs($user);

        $ot = $employee->overtimeRequests()->create([
            'work_schedule_id' => $workSchedule->id,
            'request_no' => 'OT-TEST-003',
            'ot_date' => now()->toDateString(),
            'start_at' => now()->setTime(17, 0, 0),
            'end_at' => now()->setTime(19, 0, 0),
            'reason' => 'Initial OT',
            'status' => 'pending',
        ]);

        $response = $this->putJson("/api/employee/overtime-requests/{$ot->id}", [
            'reason' => 'Updated OT reason',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.reason', 'Updated OT reason');

        $this->assertEquals('Updated OT reason', $ot->fresh()->reason);
    }

    public function test_employee_can_remove_image_when_updating_overtime_request(): void
    {
        Storage::fake('public');

        [$user, $employee, $workSchedule] = $this->setupData();
        Sanctum::actingAs($user);

        $fakePath = "requests/{$employee->id}/ot_proof.jpg";
        Storage::disk('public')->put($fakePath, 'dummy-ot-proof');

        $ot = $employee->overtimeRequests()->create([
            'work_schedule_id' => $workSchedule->id,
            'request_no' => 'OT-TEST-IMG-001',
            'ot_date' => now()->toDateString(),
            'start_at' => now()->setTime(17, 0, 0),
            'end_at' => now()->setTime(19, 0, 0),
            'file_path' => $fakePath,
            'reason' => 'OT with photo',
            'status' => 'pending',
        ]);

        $this->assertTrue(Storage::disk('public')->exists($fakePath));

        $response = $this->putJson("/api/employee/overtime-requests/{$ot->id}", [
            'reason' => 'Removed OT image',
            'remove_image' => true,
            'image' => null,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.file_path', null)
            ->assertJsonPath('data.image', null);

        $ot->refresh();
        $this->assertNull($ot->file_path);
        $this->assertFalse(Storage::disk('public')->exists($fakePath));
    }
}
