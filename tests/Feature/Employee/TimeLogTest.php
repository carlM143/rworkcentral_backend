<?php

namespace Tests\Feature\Employee;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\TimeLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TimeLogTest extends TestCase
{
    use RefreshDatabase;

    private function createEmployeeUser(int $empId = 10001): array
    {
        $admin = User::first() ?? User::factory()->create();
        $company = Company::firstOrCreate(
            ['company_name' => 'Acme Corp'],
            [
                'admin_id' => $admin->id,
                'location' => 'HQ',
                'status' => true,
            ]
        );

        $role = Role::firstOrCreate(['name' => 'Employee']);
        $user = User::factory()->create([
            'role_id' => $role->id,
        ]);

        $department = Department::firstOrCreate(
            [
                'company_id' => $company->id,
                'department_code' => 'OPS',
            ],
            [
                'admin_id' => $admin->id,
                'department_name' => 'Operations',
                'status' => true,
            ]
        );

        $employee = Employee::create([
            'user_id' => $user->id,
            'department_id' => $department->id,
            'emp_id' => $empId,
            'firstname' => 'Juan',
            'lastname' => 'Dela Cruz ' . $empId,
            'age' => 28,
            'gender' => 'Male',
            'birthday' => '1998-05-15',
            'civil_status' => 'Single',
            'status' => true,
        ]);

        return [$user, $employee];
    }

    public function test_employee_can_list_their_own_time_logs(): void
    {
        [$user, $employee] = $this->createEmployeeUser();
        [$otherUser, $otherEmployee] = $this->createEmployeeUser(10002);

        TimeLog::create([
            'employee_id' => $employee->id,
            'work_date' => Carbon::parse('2026-10-01'),
            'time_in' => Carbon::parse('2026-10-01 08:00:00'),
            'time_out' => Carbon::parse('2026-10-01 17:00:00'),
            'worked_minutes' => 480,
            'status' => 'present',
        ]);

        TimeLog::create([
            'employee_id' => $employee->id,
            'work_date' => Carbon::parse('2026-10-02'),
            'time_in' => Carbon::parse('2026-10-02 08:05:00'),
            'time_out' => Carbon::parse('2026-10-02 17:00:00'),
            'worked_minutes' => 475,
            'late_minutes' => 5,
            'status' => 'present',
        ]);

        TimeLog::create([
            'employee_id' => $otherEmployee->id,
            'work_date' => Carbon::parse('2026-10-02'),
            'time_in' => Carbon::parse('2026-10-02 08:00:00'),
            'time_out' => Carbon::parse('2026-10-02 17:00:00'),
            'worked_minutes' => 480,
            'status' => 'present',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/employee/time-logs');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Time logs retrieved successfully.',
            ])
            ->assertJsonCount(2, 'data');
    }

    public function test_employee_can_filter_time_logs_by_date_range(): void
    {
        [$user, $employee] = $this->createEmployeeUser();

        TimeLog::create([
            'employee_id' => $employee->id,
            'work_date' => Carbon::parse('2026-10-01'),
            'time_in' => Carbon::parse('2026-10-01 08:00:00'),
            'time_out' => Carbon::parse('2026-10-01 17:00:00'),
            'worked_minutes' => 480,
            'status' => 'present',
        ]);

        TimeLog::create([
            'employee_id' => $employee->id,
            'work_date' => Carbon::parse('2026-10-15'),
            'time_in' => Carbon::parse('2026-10-15 08:00:00'),
            'time_out' => Carbon::parse('2026-10-15 17:00:00'),
            'worked_minutes' => 480,
            'status' => 'present',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/employee/time-logs?start_date=2026-10-10&end_date=2026-10-20');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.work_date', '2026-10-15T00:00:00.000000Z');
    }

    public function test_employee_can_fetch_today_time_log(): void
    {
        [$user, $employee] = $this->createEmployeeUser();

        TimeLog::create([
            'employee_id' => $employee->id,
            'work_date' => Carbon::today(),
            'time_in' => Carbon::today()->setTime(8, 0),
            'worked_minutes' => 0,
            'status' => 'in_progress',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/employee/time-logs/today');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => "Today's time log retrieved successfully.",
            ])
            ->assertJsonPath('data.status', 'in_progress');
    }

    public function test_employee_today_log_returns_null_when_absent(): void
    {
        [$user, $employee] = $this->createEmployeeUser();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/employee/time-logs/today');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => null,
            ]);
    }

    public function test_unauthenticated_cannot_access_time_logs(): void
    {
        $response = $this->getJson('/api/employee/time-logs');
        $response->assertUnauthorized();
    }
}
