<?php

namespace Tests\Feature\Employee;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollGroup;
use App\Models\PayrollPeriod;
use App\Models\Role;
use App\Models\SalaryType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PayslipTest extends TestCase
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

    private function createPayrollFixtures(Employee $employee): Payroll
    {
        $payrollGroup = PayrollGroup::firstOrCreate([
            'group_name' => 'Regular Employees',
        ]);

        $company = Company::first();
        $admin = User::first();

        $payrollPeriod = PayrollPeriod::firstOrCreate(
            ['period_name' => 'Oct 1 - Oct 15, 2026'],
            [
                'user_id' => $admin->id,
                'company_id' => $company->id,
                'cutoff_start' => Carbon::parse('2026-10-01'),
                'cutoff_end' => Carbon::parse('2026-10-15'),
                'payment_date' => Carbon::parse('2026-10-20'),
                'status' => 'completed',
            ]
        );

        $salaryType = SalaryType::firstOrCreate([
            'type_name' => 'Monthly',
        ]);

        return Payroll::create([
            'employee_id' => $employee->id,
            'payroll_group_id' => $payrollGroup->id,
            'payroll_period_id' => $payrollPeriod->id,
            'salary_type_id' => $salaryType->id,
            'basic_salary' => 30000.00,
            'allowance' => 2500.00,
            'bank_name' => 'BDO',
            'account_name' => 'Juan Dela Cruz',
            'account_number' => '1234567890',
        ]);
    }

    public function test_employee_can_list_their_own_payslips(): void
    {
        [$user1, $employee1] = $this->createEmployeeUser(10001);
        [$user2, $employee2] = $this->createEmployeeUser(10002);

        $this->createPayrollFixtures($employee1);
        $this->createPayrollFixtures($employee2);

        Sanctum::actingAs($user1);

        $response = $this->getJson('/api/employee/payslips');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Payslips retrieved successfully.',
            ])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee_id', $employee1->id);
    }

    public function test_employee_can_view_specific_payslip(): void
    {
        [$user, $employee] = $this->createEmployeeUser();
        $payroll = $this->createPayrollFixtures($employee);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/employee/payslips/{$payroll->id}");

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Payslip details retrieved successfully.',
            ])
            ->assertJsonPath('data.id', $payroll->id)
            ->assertJsonPath('data.basic_salary', '30000.00');
    }

    public function test_employee_cannot_view_another_employees_payslip(): void
    {
        [$user1] = $this->createEmployeeUser(10001);
        [, $employee2] = $this->createEmployeeUser(10002);

        $otherPayroll = $this->createPayrollFixtures($employee2);

        Sanctum::actingAs($user1);

        $response = $this->getJson("/api/employee/payslips/{$otherPayroll->id}");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthorized action.',
            ]);
    }

    public function test_unauthenticated_cannot_access_payslips(): void
    {
        $response = $this->getJson('/api/employee/payslips');
        $response->assertUnauthorized();
    }
}
