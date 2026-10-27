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

class UndertimeRequestTest extends TestCase
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
            'emp_id' => 10004,
            'firstname' => 'Jose',
            'lastname' => 'Rizal',
            'age' => 35,
            'gender' => 'Male',
            'birthday' => '1991-06-19',
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

    public function test_employee_can_submit_undertime_request(): void
    {
        [$user, $employee, $workSchedule] = $this->setupData();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/employee/undertime-requests', [
            'undertime_date' => now()->toDateString(),
            'requested_time_out' => now()->setTime(15, 0, 0)->toDateTimeString(),
            'scheduled_time_out' => now()->setTime(17, 0, 0)->toDateTimeString(),
            'requested_minutes' => 120,
            'reason' => 'Doctor appointment',
            'work_schedule_id' => $workSchedule->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('undertime_requests', [
            'employee_id' => $employee->id,
            'status' => 'pending',
            'requested_minutes' => 120,
        ]);
    }

    public function test_employee_can_cancel_pending_undertime_request(): void
    {
        [$user, $employee, $workSchedule] = $this->setupData();
        Sanctum::actingAs($user);

        $ut = $employee->undertimeRequests()->create([
            'work_schedule_id' => $workSchedule->id,
            'request_no' => 'UT-TEST-001',
            'undertime_date' => now()->toDateString(),
            'requested_time_out' => now()->setTime(16, 0, 0),
            'scheduled_time_out' => now()->setTime(17, 0, 0),
            'requested_minutes' => 60,
            'reason' => 'Personal emergency',
            'status' => 'pending',
        ]);

        $response = $this->patchJson("/api/employee/undertime-requests/{$ut->id}/cancel");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertEquals('cancelled', $ut->fresh()->status);
    }

    public function test_employee_can_list_undertime_requests(): void
    {
        [$user, $employee, $workSchedule] = $this->setupData();
        Sanctum::actingAs($user);

        $employee->undertimeRequests()->create([
            'work_schedule_id' => $workSchedule->id,
            'request_no' => 'UT-TEST-002',
            'undertime_date' => now()->toDateString(),
            'requested_time_out' => now()->setTime(15, 0, 0),
            'scheduled_time_out' => now()->setTime(17, 0, 0),
            'requested_minutes' => 120,
            'reason' => 'Bank transaction',
            'status' => 'pending',
        ]);

        $response = $this->getJson('/api/employee/undertime-requests');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'Undertime');
    }

    public function test_employee_can_update_pending_undertime_request(): void
    {
        [$user, $employee, $workSchedule] = $this->setupData();
        Sanctum::actingAs($user);

        $ut = $employee->undertimeRequests()->create([
            'work_schedule_id' => $workSchedule->id,
            'request_no' => 'UT-TEST-003',
            'undertime_date' => now()->toDateString(),
            'requested_time_out' => now()->setTime(15, 0, 0),
            'scheduled_time_out' => now()->setTime(17, 0, 0),
            'requested_minutes' => 120,
            'reason' => 'Initial UT reason',
            'status' => 'pending',
        ]);

        $response = $this->putJson("/api/employee/undertime-requests/{$ut->id}", [
            'reason' => 'Updated UT reason',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.reason', 'Updated UT reason');

        $this->assertEquals('Updated UT reason', $ut->fresh()->reason);
    }

    public function test_employee_can_remove_image_when_updating_undertime_request(): void
    {
        Storage::fake('public');

        [$user, $employee, $workSchedule] = $this->setupData();
        Sanctum::actingAs($user);

        $fakePath = "requests/{$employee->id}/ut_proof.jpg";
        Storage::disk('public')->put($fakePath, 'dummy-ut-proof');

        $ut = $employee->undertimeRequests()->create([
            'work_schedule_id' => $workSchedule->id,
            'request_no' => 'UT-TEST-IMG-001',
            'undertime_date' => now()->toDateString(),
            'requested_time_out' => now()->setTime(15, 0, 0),
            'scheduled_time_out' => now()->setTime(17, 0, 0),
            'requested_minutes' => 120,
            'file_path' => $fakePath,
            'reason' => 'Doctor appointment',
            'status' => 'pending',
        ]);

        $this->assertTrue(Storage::disk('public')->exists($fakePath));

        $response = $this->putJson("/api/employee/undertime-requests/{$ut->id}", [
            'reason' => 'Removed UT image',
            'remove_image' => true,
            'image' => null,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.file_path', null)
            ->assertJsonPath('data.image', null);

        $ut->refresh();
        $this->assertNull($ut->file_path);
        $this->assertFalse(Storage::disk('public')->exists($fakePath));
    }
}
