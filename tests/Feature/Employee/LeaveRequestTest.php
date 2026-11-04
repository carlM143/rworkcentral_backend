<?php

namespace Tests\Feature\Employee;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentType;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\Role;
use App\Models\ScheduleType;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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

        $position = Position::create([
            'department_id' => $department->id,
            'position' => 'Operations Staff',
            'description' => 'Operations staff position',
            'status' => true,
        ]);

        $employee->employments()->create([
            'employment_type_id' => $employmentType->id,
            'department_id' => $department->id,
            'position_id' => $position->id,
            'date_hired' => now()->subYear()->toDateString(),
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

    public function test_leave_range_excludes_non_working_schedule_days(): void
    {
        [$user, $employee, $leaveType, $leavePolicy] = $this->setupData();
        Sanctum::actingAs($user);

        $leavePolicy->update(['annual_credits' => 31]);
        $this->assignMondayToFridaySchedule($employee);

        $response = $this->postJson('/api/employee/leave-requests', [
            'leave_type_id' => $leaveType->id,
            'requested_pay_type' => 'paid',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'reason' => 'Maternity leave',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.start', '2026-11-02')
            ->assertJsonPath('data.end', '2026-11-30')
            ->assertJsonPath('data.total_days', 21)
            ->assertJsonCount(21, 'data.dates');

        $this->assertDatabaseMissing('leave_request_dates', [
            'leave_request_id' => $response->json('data.raw_id'),
            'leave_date' => '2026-11-01',
        ]);
        $this->assertDatabaseMissing('leave_request_dates', [
            'leave_request_id' => $response->json('data.raw_id'),
            'leave_date' => '2026-11-07',
        ]);
    }

    public function test_explicit_leave_dates_also_exclude_non_working_schedule_days(): void
    {
        [$user, $employee, $leaveType] = $this->setupData();
        Sanctum::actingAs($user);

        $this->assignMondayToFridaySchedule($employee);

        $response = $this->postJson('/api/employee/leave-requests', [
            'leave_type_id' => $leaveType->id,
            'requested_pay_type' => 'paid',
            'dates' => [
                [
                    'leave_date' => '2026-11-01',
                    'day_type' => 'whole_day',
                    'requested_pay_type' => 'paid',
                ],
                [
                    'leave_date' => '2026-11-02',
                    'day_type' => 'whole_day',
                    'requested_pay_type' => 'paid',
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.total_days', 1)
            ->assertJsonCount(1, 'data.dates')
            ->assertJsonPath('data.dates.0.leave_date', '2026-11-02');
    }

    public function test_employee_can_preview_schedule_aware_leave_day_count(): void
    {
        [$user, $employee, $leaveType] = $this->setupData();
        Sanctum::actingAs($user);

        $this->assignMondayToFridaySchedule($employee);

        $response = $this->postJson('/api/employee/leave-requests/preview', [
            'leave_type_id' => $leaveType->id,
            'requested_pay_type' => 'paid',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.calendar_days', 30)
            ->assertJsonPath('data.scheduled_working_days', 21)
            ->assertJsonPath('data.excluded_rest_days', 9)
            ->assertJsonPath('data.total_days', 21)
            ->assertJsonCount(21, 'data.dates');
    }

    private function assignMondayToFridaySchedule(Employee $employee): void
    {
        $scheduleType = ScheduleType::create(['type_name' => 'Fixed']);
        $schedule = WorkSchedule::create([
            'schedule_type_id' => $scheduleType->id,
            'schedule_code' => 'MON-FRI',
            'schedule_name' => 'Monday to Friday',
            'required_work_hours' => 8,
            'grace_period' => 0,
            'status' => true,
        ]);

        foreach ([
            'Monday' => true,
            'Tuesday' => true,
            'Wednesday' => true,
            'Thursday' => true,
            'Friday' => true,
            'Sat' => false,
            'Sun' => false,
        ] as $day => $isWorkingDay) {
            if (! $isWorkingDay) {
                continue;
            }

            $schedule->days()->create([
                'day_of_week' => $day,
                'is_working_day' => true,
                'time_in' => '08:00',
                'time_out' => '17:00',
            ]);
        }

        $employee->employments()->latest('id')->firstOrFail()->update([
            'work_schedule_id' => $schedule->id,
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
            'requested_pay_type' => 'paid',
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
            'requested_pay_type' => 'paid',
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
            'requested_pay_type' => 'paid',
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

    public function test_employee_can_submit_leave_with_mixed_whole_and_half_days(): void
    {
        [$user, $employee, $leaveType, $leavePolicy] = $this->setupData();
        Sanctum::actingAs($user);

        $day1 = now()->addDays(5)->toDateString();
        $day2 = now()->addDays(6)->toDateString();

        $response = $this->postJson('/api/employee/leave-requests', [
            'leave_type_id' => $leaveType->id,
            'leave_policy_id' => $leavePolicy->id,
            'reason' => 'Doctor appointment and recovery',
            'dates' => [
                ['leave_date' => $day1, 'day_type' => 'half_day', 'day_value' => 0.5],
                ['leave_date' => $day2, 'day_type' => 'whole_day', 'day_value' => 1.0],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_days', 1.5)
            ->assertJsonCount(2, 'data.dates')
            ->assertJsonPath('data.dates.0.day_type', 'half_day')
            ->assertJsonPath('data.dates.1.day_type', 'whole_day');

        $this->assertEquals(1.5, $response->json('data.total_days'));
        $this->assertDatabaseHas('leave_request_dates', [
            'day_type' => 'half_day',
            'day_value' => 0.50,
        ]);
        $this->assertDatabaseHas('leave_request_dates', [
            'day_type' => 'whole_day',
            'day_value' => 1.00,
        ]);
    }

    public function test_employee_can_update_leave_dates_and_day_types(): void
    {
        [$user, $employee, $leaveType, $leavePolicy] = $this->setupData();
        Sanctum::actingAs($user);

        $leaveRequest = $employee->leaveRequests()->create([
            'leave_type_id' => $leaveType->id,
            'leave_policy_id' => $leavePolicy->id,
            'request_no' => 'LR-TEST-004',
            'request_date' => now()->addDays(3)->toDateString(),
            'requested_pay_type' => 'paid',
            'reason' => 'Original',
            'status' => 'pending',
        ]);
        $leaveRequest->leaveDates()->create([
            'leave_date' => now()->addDays(3)->toDateString(),
            'day_type' => 'whole_day',
            'day_value' => 1.0,
        ]);

        $newDay1 = now()->addDays(10)->toDateString();
        $newDay2 = now()->addDays(11)->toDateString();
        $newDay3 = now()->addDays(12)->toDateString();

        $response = $this->putJson("/api/employee/leave-requests/{$leaveRequest->id}", [
            'reason' => 'Changed my leave schedule',
            'dates' => [
                ['leave_date' => $newDay1, 'day_type' => 'whole_day', 'day_value' => 1.0],
                ['leave_date' => $newDay2, 'day_type' => 'half_day', 'day_value' => 0.5],
                ['leave_date' => $newDay3, 'day_type' => 'half_day', 'day_value' => 0.5],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data.dates');

        $this->assertEquals(2.0, $response->json('data.total_days'));
        $this->assertEquals(3, $leaveRequest->leaveDates()->count());
        $this->assertDatabaseHas('leave_request_dates', [
            'leave_request_id' => $leaveRequest->id,
            'day_type' => 'half_day',
        ]);
    }

    public function test_employee_can_remove_image_when_updating_request(): void
    {
        Storage::fake('public');

        [$user, $employee, $leaveType, $leavePolicy] = $this->setupData();
        Sanctum::actingAs($user);

        $fakePath = "requests/{$employee->id}/test_image.jpg";
        Storage::disk('public')->put($fakePath, 'dummy-image-content');

        $leaveRequest = $employee->leaveRequests()->create([
            'leave_type_id' => $leaveType->id,
            'leave_policy_id' => $leavePolicy->id,
            'request_no' => 'LR-TEST-IMG-001',
            'request_date' => now()->toDateString(),
            'requested_pay_type' => 'paid',
            'file_path' => $fakePath,
            'reason' => 'With image',
            'status' => 'pending',
        ]);
        $leaveRequest->leaveDates()->create([
            'leave_date' => now()->toDateString(),
            'day_type' => 'whole_day',
            'day_value' => 1.0,
        ]);

        $this->assertTrue(Storage::disk('public')->exists($fakePath));

        $response = $this->putJson("/api/employee/leave-requests/{$leaveRequest->id}", [
            'reason' => 'Removed image now',
            'remove_image' => true,
            'image' => null,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.file_path', null)
            ->assertJsonPath('data.file_url', null)
            ->assertJsonPath('data.image', null);

        $leaveRequest->refresh();
        $this->assertNull($leaveRequest->file_path);
        $this->assertFalse(Storage::disk('public')->exists($fakePath));
    }

    public function test_employee_can_set_and_change_request_date(): void
    {
        [$user, $employee, $leaveType, $leavePolicy] = $this->setupData();
        Sanctum::actingAs($user);

        $filingDate = '2026-10-20';
        $startLeave = '2026-11-01';
        $endLeave = '2026-11-03';

        $postResponse = $this->postJson('/api/employee/leave-requests', [
            'leave_type_id' => $leaveType->id,
            'leave_policy_id' => $leavePolicy->id,
            'request_date' => $filingDate,
            'start_date' => $startLeave,
            'end_date' => $endLeave,
            'reason' => 'Vacation with custom filing date',
        ]);

        $postResponse->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.request_date', $filingDate)
            ->assertJsonPath('data.start', $startLeave)
            ->assertJsonPath('data.end', $endLeave);

        $requestId = $postResponse->json('data.raw_id');

        $newFilingDate = '2026-10-25';
        $putResponse = $this->putJson("/api/employee/leave-requests/{$requestId}", [
            'request_date' => $newFilingDate,
            'reason' => 'Updated filing date',
        ]);

        $putResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.request_date', $newFilingDate);

        $this->assertEquals($newFilingDate, LeaveRequest::find($requestId)->request_date->toDateString());
    }
}
