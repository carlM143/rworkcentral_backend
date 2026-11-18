<?php

namespace Tests\Feature\Admin;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Employment;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Role;
use App\Models\TimeLog;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendanceReportTest extends TestCase
{
    private User $admin;
    private Employee $employee;
    private WorkSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        // Only the report's schema: avoid unrelated MySQL-only migrations.
        foreach ([
            '0001_01_00_000000_create_roles_table.php',
            '0001_01_01_000000_create_users_table.php',
            '2026_10_21_024400_create_companies_table.php',
            '2026_10_21_024404_create_departments_table.php',
            '2026_10_21_024848_create_employees_table.php',
            '2026_10_21_025112_create_schedule_types_table.php',
            '2026_10_21_025203_create_positions_table.php',
            '2026_10_21_025210_create_employment_types_table.php',
            '2026_10_21_025212_create_work_schedules_table.php',
            '2026_10_21_025213_create_locations_table.php',
            '2026_10_21_025214_create_employments_table.php',
            '2026_10_21_031042_create_leave_types_table.php',
            '2026_10_21_031043_create_leave_policies_table.php',
            '2026_10_21_031044_create_leave_requests_table.php',
            '2026_10_21_031411_create_employee_lifecycle_policies_table.php',
            '2026_10_21_031412_create_employee_lifecycle_stages_table.php',
            '2026_10_21_031413_create_employee_lifecycle_milestones_table.php',
            '2026_10_21_031413_create_leave_request_dates_table.php',
            '2026_10_21_031751_create_time_logs_table.php',
            '2026_10_21_031752_create_overtime_requests_table.php',
            '2026_10_21_033200_create_holiday_types_table.php',
            '2026_10_21_033200_create_holidays_table.php',
            '2026_10_29_014726_create_work_schedule_days_table.php',
            '2026_10_30_151602_create_time_log_correction_requests_table.php',
            '2026_10_31_113703_add_attendance_schedule_snapshot_to_time_logs_table.php',
            '2026_11_04_013150_create_holiday_locations_table.php',
            '2026_11_04_060000_support_work_calendar.php',
            '2026_11_05_010000_add_holiday_employee_coverage.php',
            '2026_11_05_113338_create_rest_day_switch_requests_table.php',
            '2026_11_09_023059_create_work_schedule_revisions_table.php',
            '2026_11_10_070833_create_holiday_dates_table.php',
            '2026_11_12_120000_create_lifecycle_evaluation_documents_table.php',
            '2026_11_12_130000_complete_lifecycle_workflows.php',
        ] as $migration) (require database_path('migrations/'.$migration))->up();
        $this->travelTo(now()->setDate(2026, 11, 17)->setTime(12, 0));
        $this->admin = User::factory()->create(['role_id' => Role::firstOrCreate(['name' => 'Administrator'])->id]);
        Sanctum::actingAs($this->admin);
        $company = Company::create(['admin_id' => $this->admin->id, 'company_name' => 'Example']);
        $department = Department::create(['admin_id' => $this->admin->id, 'company_id' => $company->id, 'department_name' => 'Operations', 'department_code' => 'OPS', 'status' => true]);
        $this->employee = Employee::create(['department_id' => $department->id, 'emp_id' => 1001, 'firstname' => 'Alice', 'lastname' => 'Example', 'birthday' => '1990-01-01', 'status' => true]);
        $scheduleType = DB::table('schedule_types')->insertGetId(['type_name' => 'Regular']);
        $this->schedule = WorkSchedule::create(['schedule_type_id' => $scheduleType, 'schedule_code' => 'DAY', 'schedule_name' => 'Day shift', 'required_work_hours' => 8]);
        foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'] as $day) {
            $this->schedule->days()->create(['day_of_week' => $day, 'is_working_day' => true, 'time_in' => '08:00:00', 'time_out' => '17:00:00']);
        }
        $type = DB::table('employment_types')->insertGetId(['name' => 'Regular']);
        $position = DB::table('positions')->insertGetId(['department_id' => $department->id, 'position' => 'Staff', 'description' => 'Staff', 'status' => true]);
        Employment::create(['employee_id' => $this->employee->id, 'employment_type_id' => $type, 'department_id' => $department->id, 'position_id' => $position, 'work_schedule_id' => $this->schedule->id, 'date_hired' => '2026-11-09']);
    }

    private function url(array $query = []): string
    {
        return '/api/admin/reports/attendance?'.http_build_query([...['start' => '2026-11-09', 'end' => '2026-11-18', 'details' => 1], ...$query]);
    }

    public function test_all_employees_totals_missing_logs_leave_holidays_and_future_days(): void
    {
        Employee::create(['emp_id' => 1002, 'firstname' => 'Bob', 'lastname' => 'No schedule', 'birthday' => '1990-01-01', 'status' => true]);
        TimeLog::create(['employee_id' => $this->employee->id, 'work_date' => '2026-11-09', 'time_in' => '2026-11-09 08:10:00', 'time_out' => '2026-11-09 17:00:00', 'worked_minutes' => 470, 'chargeable_late_minutes' => 5, 'late_minutes' => 10, 'source' => 'qr', 'status' => 'Present']);
        TimeLog::create(['employee_id' => $this->employee->id, 'work_date' => '2026-11-10', 'time_in' => '2026-11-10 08:00:00', 'worked_minutes' => 480, 'source' => 'wfh', 'status' => 'Incomplete']);
        $type = DB::table('leave_types')->insertGetId(['user_id' => $this->admin->id, 'leave_code' => 'VL', 'leave_name' => 'Vacation']);
        $leave = LeaveRequest::create(['employee_id' => $this->employee->id, 'leave_type_id' => $type, 'request_no' => 'LEAVE-1', 'request_date' => '2026-11-01', 'requested_pay_type' => 'paid', 'status' => 'approved']);
        $leave->leaveDates()->create(['leave_date' => '2026-11-11', 'day_type' => 'whole_day', 'day_value' => 1]);
        $leave->leaveDates()->create(['leave_date' => '2026-11-12', 'day_type' => 'half_day', 'day_value' => 0.5]);
        Holiday::create(['company_id' => $this->employee->department->company_id, 'user_id' => $this->admin->id, 'holiday_type_id' => DB::table('holiday_types')->value('id'), 'holiday_name' => 'Company holiday', 'holiday_date' => '2026-11-13', 'is_working_day' => false, 'status' => true]);
        OvertimeRequest::create(['employee_id' => $this->employee->id, 'work_schedule_id' => $this->schedule->id, 'request_no' => 'OT-1', 'ot_date' => '2026-11-09', 'start_at' => '2026-11-09 17:00:00', 'end_at' => '2026-11-09 19:00:00', 'reason' => 'Support', 'status' => 'approved']);
        $response = $this->getJson($this->url())->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('totals.employees', 2)->assertJsonPath('totals.presentDays', 2)
            ->assertJsonPath('totals.absentDays', 1.5)->assertJsonPath('totals.leaveDays', 1.5)
            ->assertJsonPath('totals.workedMinutes', 470)->assertJsonPath('totals.overtimeMinutes', 120)
            ->assertJsonPath('totals.missingPunchDays', 1)->assertJsonPath('totals.lateMinutes', 5);
        $days = collect($response->json('data.0.days'))->keyBy('date');
        $this->assertSame('Rest day', $days['2026-11-14']['status']);
        $this->assertSame('Scheduled', $days['2026-11-17']['status']);
        $this->assertSame('Scheduled', $days['2026-11-18']['status']);
        $this->assertSame('No employment record', $response->json('data.1.days.0.status'));
        $this->getJson($this->url(['condition' => 'no_logs']))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('totals.employees', 1);
        $this->getJson($this->url(['search' => 'Alice']))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('totals.workedMinutes', 470);
    }

    public function test_historical_revision_snapshots_and_employment_boundaries(): void
    {
        $revision = $this->schedule->revisions()->create(['effective_from' => '2026-11-01', 'required_work_hours' => 8]);
        $revision->days()->create(['day_of_week' => 'Monday', 'time_in' => '22:00:00', 'time_out' => '06:00:00']);
        $r = $this->getJson($this->url(['start' => '2026-11-08', 'end' => '2026-11-10']))->assertOk();
        $this->assertSame('Outside employment', $r->json('data.0.days.0.status'));
        $this->assertSame('2026-11-10 06:00:00', $r->json('data.0.days.1.scheduledOut'));
        $this->assertSame('Rest day', $r->json('data.0.days.2.status'));
        $this->employee->update(['status' => false]);
        $this->getJson($this->url())->assertOk()->assertJsonPath('totals.absentDays', 0);
        TimeLog::create(['employee_id' => $this->employee->id, 'work_date' => '2026-11-10', 'scheduled_time_in' => '2026-11-10 09:00:00', 'scheduled_time_out' => '2026-11-10 18:00:00', 'time_in' => '2026-11-10 09:00:00', 'status' => 'Incomplete']);
        $r = $this->getJson($this->url(['start' => '2026-11-10', 'end' => '2026-11-10']))->assertOk();
        $this->assertSame('2026-11-10 09:00:00', $r->json('data.0.days.0.scheduledIn'));
        $this->assertSame('Missing punch', $r->json('data.0.days.0.status'));
    }

    public function test_access_and_date_validation(): void
    {
        $this->getJson($this->url(['start' => '2026-11-19']))->assertUnprocessable();
        $this->getJson($this->url(['start' => '2024-01-01']))->assertUnprocessable();
        Sanctum::actingAs(User::factory()->create(['role_id' => Role::firstOrCreate(['name' => 'Employee'])->id]));
        $this->getJson($this->url())->assertForbidden();
    }
}
