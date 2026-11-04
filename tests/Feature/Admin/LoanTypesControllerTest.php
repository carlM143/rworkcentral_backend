<?php

namespace Tests\Feature\Admin;

use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\LoanType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LoanTypesControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_edit_and_update_a_loan_type(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $loanType = $this->createLoanType();

        $this->getJson("/api/admin/loan-types/{$loanType->id}/edit")
            ->assertOk()
            ->assertJsonPath('data.id', $loanType->id)
            ->assertJsonPath('data.loan_code', 'SAL');

        $this->patchJson("/api/admin/loan-types/{$loanType->id}", [
            'loan_code' => 'sal-updated',
            'loan_name' => 'Updated Salary Loan',
            'provider' => 'RWorkCentral',
            'status' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.loan_code', 'SAL-UPDATED')
            ->assertJsonPath('data.loan_name', 'Updated Salary Loan')
            ->assertJsonPath('data.status', false);

        $this->assertDatabaseHas('loan_types', [
            'id' => $loanType->id,
            'loan_code' => 'SAL-UPDATED',
            'status' => false,
        ]);
    }

    public function test_admin_can_delete_an_unused_loan_type(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $loanType = $this->createLoanType();

        $this->deleteJson("/api/admin/loan-types/{$loanType->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('loan_types', ['id' => $loanType->id]);
    }

    public function test_admin_cannot_delete_a_loan_type_used_by_an_employee_loan(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $loanType = $this->createLoanType();
        $employee = Employee::create([
            'emp_id' => 1002,
            'firstname' => 'Juan',
            'lastname' => 'Dela Cruz',
            'birthday' => '1996-01-01',
            'gender' => 'Male',
            'civil_status' => 'Single',
            'status' => true,
        ]);

        EmployeeLoan::create([
            'employee_id' => $employee->id,
            'loan_type_id' => $loanType->id,
            'created_by' => $user->id,
            'loan_reference_no' => 'LOAN-001',
            'principal_amount' => 1000,
            'total_payable_amount' => 1200,
            'opening_balance' => 1200,
            'deduction_amount' => 200,
            'loan_date' => '2026-11-01',
            'deduction_start_date' => '2026-11-15',
            'status' => true,
        ]);

        $this->deleteJson("/api/admin/loan-types/{$loanType->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('loan_types', ['id' => $loanType->id]);
    }

    private function createLoanType(): LoanType
    {
        return LoanType::create([
            'loan_code' => 'SAL',
            'loan_name' => 'Salary Loan',
            'provider' => 'Company',
            'status' => true,
        ]);
    }
}
