<?php

namespace Tests\Feature\Admin;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentType;
use App\Models\HolidayType;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_saves_scopes_edits_and_removes_entries(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $company = Company::create(['admin_id' => $user->id, 'company_name' => 'Example']);
        $main = $company->locations()->create(['location_name' => 'Main', 'city' => 'Manila']);
        $branch = $company->locations()->create(['location_name' => 'Branch', 'city' => 'Davao']);
        $payload = ['holiday_name' => 'Local Holiday', 'holiday_date' => '2026-11-05', 'holiday_type_id' => HolidayType::first()->id, 'is_working_day' => false, 'applies_to' => 'specific_locations', 'location_ids' => [$main->id]];
        $id = $this->postJson('/api/admin/work-calendar', $payload)->assertCreated()->json('data.id');
        $url = '/api/admin/work-calendar?start=2026-11-01&end=2026-11-30&location_id=';
        $this->getJson($url.$main->id)->assertOk()->assertJsonCount(1, 'data.holidays');
        $this->getJson($url.$branch->id)->assertOk()->assertJsonCount(0, 'data.holidays');
        $this->putJson("/api/admin/work-calendar/{$id}", [...$payload, 'applies_to' => 'all_locations', 'location_ids' => [], 'is_working_day' => true])
            ->assertOk()->assertJsonPath('data.is_working_day', true)->assertJsonCount(0, 'data.locations');
        $this->getJson($url.$branch->id)->assertOk()->assertJsonCount(1, 'data.holidays');
        $this->postJson('/api/admin/work-calendar', [...$payload, 'location_ids' => []])->assertUnprocessable();
        $other = Company::create(['admin_id' => $user->id, 'company_name' => 'Other']);
        $foreign = $other->locations()->create(['location_name' => 'Foreign', 'city' => 'Cebu']);
        $this->postJson('/api/admin/work-calendar', [...$payload, 'location_ids' => [$foreign->id]])->assertUnprocessable();
        $this->deleteJson("/api/admin/work-calendar/{$id}")->assertOk();
        $this->getJson($url.$main->id)->assertOk()->assertJsonCount(0, 'data.holidays');
        $this->assertDatabaseHas('holidays', ['id' => $id, 'status' => false]);
    }

    public function test_calendar_only_shows_actual_approved_leave_dates_at_current_location(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $company = Company::create(['admin_id' => $user->id, 'company_name' => 'Example']);
        $main = $company->locations()->create(['location_name' => 'Main', 'city' => 'Manila']);
        $branch = $company->locations()->create(['location_name' => 'Branch', 'city' => 'Davao']);
        $department = Department::create(['admin_id' => $user->id, 'company_id' => $company->id, 'department_name' => 'Operations', 'department_code' => 'OPS', 'status' => true]);
        $position = Position::create(['department_id' => $department->id, 'position' => 'Staff', 'description' => 'Staff']);
        $type = EmploymentType::create(['name' => 'Regular']);
        $employee = Employee::create(['user_id' => $user->id, 'department_id' => $department->id, 'emp_id' => 12345678, 'firstname' => 'Test', 'lastname' => 'Employee', 'birthday' => '1990-01-01', 'age' => 36, 'gender' => 'Male', 'civil_status' => 'Single']);
        $employment = ['employment_type_id' => $type->id, 'department_id' => $department->id, 'position_id' => $position->id, 'date_hired' => '2026-01-01'];
        $employee->employments()->create([...$employment, 'location_id' => $branch->id]);
        $employee->employments()->create([...$employment, 'location_id' => $main->id]);
        $leaveType = LeaveType::create(['user_id' => $user->id, 'leave_code' => 'VL', 'leave_name' => 'Vacation Leave', 'status' => 'active']);
        foreach (['approved', 'pending', 'rejected', 'cancelled'] as $status) {
            $leave = LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'request_no' => $status, 'request_date' => '2026-11-01', 'requested_pay_type' => 'paid', 'status' => $status]);
            foreach (['2026-11-05', '2026-11-07'] as $date) {
                $leave->leaveDates()->create(['leave_date' => $date, 'day_type' => 'half_day', 'day_value' => 0.5]);
            }
        }
        $url = '/api/admin/work-calendar?start=2026-11-01&end=2026-11-30&location_id=';
        $this->getJson($url.$main->id)->assertOk()->assertJsonCount(2, 'data.leaves')->assertJsonPath('data.leaves.0.employee_name', 'Test Employee')->assertJsonPath('data.leaves.0.day_value', '0.50');
        $this->getJson($url.$branch->id)->assertOk()->assertJsonCount(0, 'data.leaves');
        $this->getJson('/api/admin/work-calendar?start=2026-11-06&end=2026-11-06')->assertOk()->assertJsonCount(0, 'data.leaves');
        LeaveRequest::where('status', 'approved')->update(['status' => 'pending']);
        $this->getJson($url.$main->id)->assertOk()->assertJsonCount(0, 'data.leaves');
        $scheduleType = \Illuminate\Support\Facades\DB::table('schedule_types')->insertGetId(['type_name' => 'Regular']);
        $schedule = \Illuminate\Support\Facades\DB::table('work_schedules')->insertGetId(['schedule_type_id' => $scheduleType, 'schedule_code' => 'DAY', 'schedule_name' => 'Day', 'required_work_hours' => 8]);
        foreach (['approved', 'pending', 'rejected', 'cancelled'] as $status) {
            \App\Models\OvertimeRequest::create(['employee_id' => $employee->id, 'work_schedule_id' => $schedule, 'request_no' => 'OT-'.$status, 'ot_date' => '2026-11-08', 'start_at' => '2026-11-08 22:00:00', 'end_at' => '2026-11-09 01:00:00', 'status' => $status]);
            \App\Models\UndertimeRequest::create(['employee_id' => $employee->id, 'work_schedule_id' => $schedule, 'request_no' => 'UT-'.$status, 'undertime_date' => '2026-11-09', 'requested_time_out' => '2026-11-09 16:00:00', 'scheduled_time_out' => '2026-11-09 17:00:00', 'requested_minutes' => 60, 'reason' => 'Appointment', 'status' => $status]);
        }
        $response = $this->getJson($url.$main->id)->assertOk()->assertJsonCount(2, 'data.leaves')
            ->assertJsonPath('data.leaves.0.category', 'overtime')
            ->assertJsonPath('data.leaves.0.date', '2026-11-08')
            ->assertJsonPath('data.leaves.0.time_label', 'Nov 8, 22:00 – Nov 9, 01:00')
            ->assertJsonPath('data.leaves.1.category', 'undertime')
            ->assertJsonPath('data.leaves.1.date', '2026-11-09');
        $this->assertSame(['leave:'.$leaveType->id, 'overtime', 'undertime'], array_column($response->json('data.request_types'), 'value'));
        $this->getJson($url.$branch->id)->assertOk()->assertJsonCount(0, 'data.leaves');
        $this->getJson('/api/admin/work-calendar?start=2026-12-01&end=2026-12-31')->assertOk()->assertJsonCount(0, 'data.leaves');

        $employee->update(['birthday' => '1990-11-10', 'status' => true]);
        $employee->employments()->latest('id')->first()->update(['date_hired' => '2024-11-11']);
        \App\Models\TimeLog::create(['employee_id' => $employee->id, 'work_date' => '2026-11-12', 'time_in' => '2026-11-12 08:15:00', 'late_minutes' => 15, 'raw_late_minutes' => 15, 'chargeable_late_minutes' => 0]);
        \App\Models\TimeLog::create(['employee_id' => $employee->id, 'work_date' => '2026-11-13', 'time_in' => '2026-11-13 08:00:00', 'late_minutes' => 0]);
        \App\Models\TimeLog::create(['employee_id' => $employee->id, 'work_date' => '2026-11-14', 'late_minutes' => 20]);
        $this->getJson($url.$main->id)->assertOk()->assertJsonCount(3, 'data.employee_events')
            ->assertJsonPath('data.employee_events.0.category', 'birthday')
            ->assertJsonPath('data.employee_events.0.date', '2026-11-10')
            ->assertJsonPath('data.employee_events.1.category', 'anniversary')
            ->assertJsonPath('data.employee_events.1.time_label', '2 years of service')
            ->assertJsonPath('data.employee_events.2.category', 'late')
            ->assertJsonPath('data.employee_events.2.time_label', '15 min recorded late · In 08:15');
        $this->getJson($url.$branch->id)->assertOk()->assertJsonCount(0, 'data.employee_events');
        $this->getJson('/api/admin/work-calendar?start=2026-12-01&end=2026-12-31')->assertOk()->assertJsonCount(0, 'data.employee_events');
        $employee->update(['status' => false]);
        $this->getJson($url.$main->id)->assertOk()->assertJsonCount(1, 'data.employee_events')->assertJsonPath('data.employee_events.0.category', 'late');
        $employee->update(['status' => true, 'birthday' => '2000-02-29']);
        $this->getJson('/api/admin/work-calendar?start=2027-02-28&end=2027-03-01')->assertOk()->assertJsonCount(0, 'data.employee_events');
        $this->getJson('/api/admin/work-calendar?start=2028-02-28&end=2028-03-01')->assertOk()->assertJsonCount(1, 'data.employee_events')->assertJsonPath('data.employee_events.0.date', '2028-02-29');

        $holidayType = HolidayType::where('status', true)->first();
        $all = \App\Models\Holiday::create(['company_id' => $company->id, 'user_id' => $user->id, 'holiday_type_id' => $holidayType->id, 'holiday_name' => 'Company Holiday', 'holiday_date' => '2026-11-06', 'applies_to' => 'all_locations', 'is_working_day' => false, 'status' => true]);
        $local = \App\Models\Holiday::create(['company_id' => $company->id, 'user_id' => $user->id, 'holiday_type_id' => $holidayType->id, 'holiday_name' => 'Branch Holiday', 'holiday_date' => '2026-11-07', 'applies_to' => 'specific_locations', 'is_working_day' => false, 'status' => true]);
        $local->locations()->attach($branch->id);
        $otherUser = User::factory()->create();
        $otherEmployee = Employee::create(['user_id' => $otherUser->id, 'department_id' => $department->id, 'emp_id' => 87654321, 'firstname' => 'Other', 'lastname' => 'Person', 'birthday' => '1990-11-10', 'status' => true]);
        $otherEmployee->employments()->create([...$employment, 'location_id' => $branch->id]);
        \App\Models\TimeLog::create(['employee_id' => $otherEmployee->id, 'work_date' => '2026-11-12', 'time_in' => '2026-11-12 08:20:00', 'late_minutes' => 20]);
        Sanctum::actingAs($user);
        $personal = $this->getJson('/api/employee/calendar?start=2026-11-01&end=2026-11-30&location_id='.$branch->id)->assertOk();
        $this->assertContains('Company Holiday', array_column($personal->json('data.events'), 'title'));
        $this->assertNotContains('Branch Holiday', array_column($personal->json('data.events'), 'title'));
        $this->assertNotContains('late-'.$otherEmployee->timeLogs()->first()->id, array_column($personal->json('data.events'), 'id'));
        $personal->assertJsonPath('data.location.name', 'Main');
        Sanctum::actingAs($otherUser);
        $otherResponse = $this->getJson('/api/employee/calendar?start=2026-11-01&end=2026-11-30')->assertOk();
        $this->assertContains('Branch Holiday', array_column($otherResponse->json('data.events'), 'title'));
        $this->assertNotContains('late-'.$employee->timeLogs()->first()->id, array_column($otherResponse->json('data.events'), 'id'));
        $otherEmployee->employments()->latest('id')->first()->update(['location_id' => null]);
        $unassigned = $this->getJson('/api/employee/calendar?start=2026-11-01&end=2026-11-30')->assertOk()->assertJsonPath('data.location', null);
        $this->assertContains('Company Holiday', array_column($unassigned->json('data.events'), 'title'));
        $this->assertNotContains('Branch Holiday', array_column($unassigned->json('data.events'), 'title'));

        Sanctum::actingAs($user);
        $eventPayload = ['name' => 'Team Meeting', 'event_date' => '2026-11-10', 'event_time' => '14:30', 'description' => 'Planning', 'applies_to' => 'specific_locations', 'location_ids' => [$main->id]];
        $eventId = $this->postJson('/api/admin/calendar-events', $eventPayload)->assertCreated()->json('data.id');
        $this->getJson($url.$main->id)->assertOk()->assertJsonCount(1, 'data.company_events');
        $this->getJson($url.$branch->id)->assertOk()->assertJsonCount(0, 'data.company_events');
        $this->postJson('/api/admin/calendar-events', [...$eventPayload, 'location_ids' => []])->assertUnprocessable();
        $this->postJson('/api/admin/calendar-events', [...$eventPayload, 'event_time' => 'not-time'])->assertUnprocessable();
        $this->getJson('/api/employee/calendar?start=2026-11-01&end=2026-11-30')->assertOk()->assertJsonFragment(['title' => 'Team Meeting', 'detail' => '14:30 · Planning']);
        Sanctum::actingAs($otherUser);
        $this->assertNotContains('Team Meeting', array_column($this->getJson('/api/employee/calendar?start=2026-11-01&end=2026-11-30')->assertOk()->json('data.events'), 'title'));
        Sanctum::actingAs($user);
        $this->putJson("/api/admin/calendar-events/{$eventId}", [...$eventPayload, 'name' => 'All Hands', 'event_time' => null, 'applies_to' => 'all_locations', 'location_ids' => []])->assertOk()->assertJsonPath('data.name', 'All Hands');
        Sanctum::actingAs($otherUser);
        $this->assertContains('All Hands', array_column($this->getJson('/api/employee/calendar?start=2026-11-01&end=2026-11-30')->assertOk()->json('data.events'), 'title'));
        Sanctum::actingAs($user);
        $this->deleteJson("/api/admin/calendar-events/{$eventId}")->assertOk();
        $this->getJson($url.$main->id)->assertOk()->assertJsonCount(0, 'data.company_events');
    }
}
