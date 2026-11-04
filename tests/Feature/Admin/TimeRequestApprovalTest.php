<?php

namespace Tests\Feature\Admin;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentType;
use App\Models\OvertimeRequest;
use App\Models\Position;
use App\Models\ScheduleType;
use App\Models\UndertimeRequest;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TimeRequestApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_list_and_approve_an_overtime_request(): void
    {
        $approver = User::factory()->create();
        [$employee, $workSchedule] = $this->createEmployeeAndSchedule();
        $overtimeRequest = OvertimeRequest::create([
            'employee_id' => $employee->id,
            'work_schedule_id' => $workSchedule->id,
            'request_no' => 'OT-0001',
            'ot_date' => '2026-10-27',
            'start_at' => '2026-10-27 17:00:00',
            'end_at' => '2026-10-27 19:00:00',
            'reason' => 'Complete urgent work.',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($approver);

        $this->getJson('/api/admin/overtime-requests')
            ->assertOk()
            ->assertJsonPath('data.0.id', $overtimeRequest->id)
            ->assertJsonPath('data.0.employee.id', $employee->id)
            ->assertJsonPath('data.0.employee.position', 'Software Engineer')
            ->assertJsonPath('data.0.work_schedule.days.0.day_of_week', 'Tuesday')
            ->assertJsonPath('data.0.work_schedule.days.0.time_in', '08:00:00')
            ->assertJsonPath('data.0.work_schedule.days.0.time_out', '17:00:00');

        $this->patchJson("/api/admin/overtime-requests/{$overtimeRequest->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approved_by', $approver->id);

        $this->assertDatabaseHas('overtime_requests', [
            'id' => $overtimeRequest->id,
            'status' => 'approved',
            'approved_by' => $approver->id,
        ]);
    }

    public function test_authenticated_user_can_list_and_approve_an_undertime_request(): void
    {
        $approver = User::factory()->create();
        [$employee, $workSchedule] = $this->createEmployeeAndSchedule();
        $undertimeRequest = UndertimeRequest::create([
            'employee_id' => $employee->id,
            'work_schedule_id' => $workSchedule->id,
            'request_no' => 'UT-0001',
            'undertime_date' => '2026-10-27',
            'requested_time_out' => '2026-10-27 16:00:00',
            'scheduled_time_out' => '2026-10-27 17:00:00',
            'requested_minutes' => 60,
            'reason' => 'Personal appointment.',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($approver);

        $this->getJson('/api/admin/undertime-requests')
            ->assertOk()
            ->assertJsonPath('data.0.id', $undertimeRequest->id)
            ->assertJsonPath('data.0.employee.id', $employee->id)
            ->assertJsonPath('data.0.employee.position', 'Software Engineer')
            ->assertJsonPath('data.0.employee.position_details.position', 'Software Engineer')
            ->assertJsonPath('data.0.work_schedule.days.0.day_of_week', 'Tuesday')
            ->assertJsonPath('data.0.work_schedule.days.0.time_in', '08:00:00')
            ->assertJsonPath('data.0.work_schedule.days.0.time_out', '17:00:00');

        $this->patchJson("/api/admin/undertime-requests/{$undertimeRequest->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approved_by', $approver->id);

        $this->assertDatabaseHas('undertime_requests', [
            'id' => $undertimeRequest->id,
            'status' => 'approved',
            'approved_by' => $approver->id,
        ]);
    }

    public function test_authenticated_user_can_reject_overtime_and_undertime_requests(): void
    {
        $approver = User::factory()->create();
        [$employee, $workSchedule] = $this->createEmployeeAndSchedule();

        $overtimeRequest = OvertimeRequest::create([
            'employee_id' => $employee->id,
            'work_schedule_id' => $workSchedule->id,
            'request_no' => 'OT-0002',
            'ot_date' => '2026-10-28',
            'start_at' => '2026-10-28 17:00:00',
            'end_at' => '2026-10-28 19:00:00',
            'reason' => 'Extra shift.',
            'status' => 'pending',
        ]);

        $undertimeRequest = UndertimeRequest::create([
            'employee_id' => $employee->id,
            'work_schedule_id' => $workSchedule->id,
            'request_no' => 'UT-0002',
            'undertime_date' => '2026-10-28',
            'requested_time_out' => '2026-10-28 16:00:00',
            'scheduled_time_out' => '2026-10-28 17:00:00',
            'requested_minutes' => 60,
            'reason' => 'Errand.',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($approver);

        $this->patchJson("/api/admin/overtime-requests/{$overtimeRequest->id}/reject")
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.approved_by', $approver->id);

        $this->patchJson("/api/admin/undertime-requests/{$undertimeRequest->id}/reject")
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.approved_by', $approver->id);

        $this->assertDatabaseHas('overtime_requests', [
            'id' => $overtimeRequest->id,
            'status' => 'rejected',
            'approved_by' => $approver->id,
        ]);

        $this->assertDatabaseHas('undertime_requests', [
            'id' => $undertimeRequest->id,
            'status' => 'rejected',
            'approved_by' => $approver->id,
        ]);
    }

    private function createEmployeeAndSchedule(): array
    {
        $owner = User::factory()->create();
        $company = Company::create([
            'admin_id' => $owner->id,
            'company_name' => 'Test Company',
            'location' => 'Manila',
            'status' => true,
        ]);
        $department = Department::create([
            'admin_id' => $owner->id,
            'company_id' => $company->id,
            'department_name' => 'Information Technology',
            'department_code' => 'IT',
            'status' => true,
        ]);
        $position = Position::create([
            'department_id' => $department->id,
            'position' => 'Software Engineer',
            'description' => 'Develops business applications.',
            'status' => true,
        ]);
        $employmentType = EmploymentType::create([
            'name' => 'Regular',
            'description' => 'Regular employment',
            'status' => 'active',
        ]);

        $employee = Employee::create([
            'emp_id' => 12345678,
            'firstname' => 'Juan',
            'lastname' => 'Dela Cruz',
            'age' => 30,
            'gender' => 'Male',
            'birthday' => '1996-01-01',
            'civil_status' => 'Single',
            'department_id' => $department->id,
            'status' => true,
        ]);

        $scheduleType = ScheduleType::create([
            'type_name' => 'Fixed Schedule',
        ]);

        $workSchedule = WorkSchedule::create([
            'schedule_type_id' => $scheduleType->id,
            'schedule_code' => 'FIXED-01',
            'schedule_name' => 'Regular Office Hours',
            'required_work_hours' => 8,
            'grace_period' => 0,
            'status' => true,
        ]);

        $workSchedule->days()->create([
            'day_of_week' => 'Tuesday',
            'is_working_day' => true,
            'time_in' => '08:00:00',
            'time_out' => '17:00:00',
        ]);

        $employee->employments()->create([
            'employment_type_id' => $employmentType->id,
            'department_id' => $department->id,
            'position_id' => $position->id,
            'work_schedule_id' => $workSchedule->id,
            'date_hired' => '2026-01-01',
        ]);

        return [$employee, $workSchedule];
    }
}
