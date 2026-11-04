<?php

namespace Tests\Feature\Admin;

use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\EmployeeLoanTransaction;
use App\Models\LoanType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeLoanTransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_fetch_an_employees_loan_deduction_history(): void
    {
        [$user, $employee, $loan] = $this->createLoan();

        EmployeeLoanTransaction::create([
            'employee_loan_id' => $loan->id,
            'transaction_type' => 'payroll_deduction',
            'amount_delta' => -200,
            'transaction_date' => '2026-11-01',
            'reference_no' => 'PAYROLL-LOAN-001',
            'remarks' => 'First payroll deduction',
            'created_by' => $user->id,
            'status' => true,
        ]);

        EmployeeLoanTransaction::create([
            'employee_loan_id' => $loan->id,
            'transaction_type' => 'adjustment',
            'amount_delta' => -50,
            'transaction_date' => '2026-11-02',
            'reference_no' => 'LOAN-ADJUSTMENT-001',
            'created_by' => $user->id,
            'status' => true,
        ]);

        Sanctum::actingAs($user);

        $this->getJson(
            "/api/admin/employee-loan-transactions?employee_id={$employee->id}&transaction_type=payroll_deduction"
        )
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.employee.id', $employee->id)
            ->assertJsonPath('data.data.0.employee.name', 'Juan Dela Cruz')
            ->assertJsonPath('data.data.0.loan_type.loan_name', 'Salary Loan')
            ->assertJsonPath('data.data.0.transaction_type', 'payroll_deduction')
            ->assertJsonPath('data.data.0.amount_delta', -200)
            ->assertJsonPath('data.data.0.deduction_amount', 200);
    }

    public function test_admin_can_fetch_a_loans_running_deduction_history(): void
    {
        [$user, $employee, $loan] = $this->createLoan();

        EmployeeLoanTransaction::create([
            'employee_loan_id' => $loan->id,
            'transaction_type' => 'payroll_deduction',
            'amount_delta' => -200,
            'transaction_date' => '2026-11-01',
            'reference_no' => 'PAYROLL-LOAN-002',
            'created_by' => $user->id,
            'status' => true,
        ]);

        EmployeeLoanTransaction::create([
            'employee_loan_id' => $loan->id,
            'transaction_type' => 'adjustment',
            'amount_delta' => -50,
            'transaction_date' => '2026-11-02',
            'reference_no' => 'LOAN-ADJUSTMENT-002',
            'created_by' => $user->id,
            'status' => true,
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/admin/employee-loans/{$loan->id}/transactions")
            ->assertOk()
            ->assertJsonPath('data.loan.employee.id', $employee->id)
            ->assertJsonPath('data.summary.opening_balance', 1200)
            ->assertJsonPath('data.summary.total_deducted', 200)
            ->assertJsonPath('data.summary.net_amount_delta', -250)
            ->assertJsonPath('data.summary.current_balance', 950)
            ->assertJsonPath('data.summary.transaction_count', 2)
            ->assertJsonPath('data.transactions.0.balance_after', 1000)
            ->assertJsonPath('data.transactions.1.balance_after', 950);
    }

    private function createLoan(): array
    {
        $user = User::factory()->create();
        $employee = Employee::create([
            'emp_id' => 1002,
            'firstname' => 'Juan',
            'lastname' => 'Dela Cruz',
            'birthday' => '1996-01-01',
            'gender' => 'Male',
            'civil_status' => 'Single',
            'status' => true,
        ]);
        $loanType = LoanType::create([
            'loan_code' => 'SAL',
            'loan_name' => 'Salary Loan',
            'provider' => 'Company',
            'status' => true,
        ]);
        $loan = EmployeeLoan::create([
            'employee_id' => $employee->id,
            'loan_type_id' => $loanType->id,
            'created_by' => $user->id,
            'loan_reference_no' => 'LOAN-2026-001',
            'principal_amount' => 1000,
            'total_payable_amount' => 1200,
            'opening_balance' => 1200,
            'deduction_amount' => 200,
            'loan_date' => '2026-10-01',
            'deduction_start_date' => '2026-11-01',
            'status' => true,
        ]);

        return [$user, $employee, $loan];
    }
}
