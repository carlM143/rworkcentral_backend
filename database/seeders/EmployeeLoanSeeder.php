<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\LoanType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class EmployeeLoanSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('email', 'hr@rworkcentral.test')->firstOrFail();

        $loans = [
            [
                'emp_id' => 1001,
                'loan_type_code' => 'SSS-SL',
                'loan_reference_no' => 'Loan-564636',
                'principal_amount' => 10000,
                'total_payable_amount' => 10000,
                'deduction_amount' => 250,
                'loan_date' => '2026-11-03',
                'deduction_start_date' => '2026-11-04',
                'remarks' => 'test',
            ],
        ];

        foreach ($loans as $loan) {
            $employee = Employee::where('emp_id', $loan['emp_id'])->firstOrFail();

            EmployeeLoan::updateOrCreate(
                ['loan_reference_no' => $loan['loan_reference_no']],
                [
                    'employee_id' => $employee->id,
                    'loan_type_id' => $this->loanTypeId($loan['loan_type_code']),
                    'created_by' => $admin->id,
                    'principal_amount' => $loan['principal_amount'],
                    'total_payable_amount' => $loan['total_payable_amount'],
                    'opening_balance' => $loan['total_payable_amount'],
                    'deduction_amount' => $loan['deduction_amount'],
                    'loan_date' => $loan['loan_date'],
                    'deduction_start_date' => $loan['deduction_start_date'],
                    'remarks' => $loan['remarks'],
                    'status' => true,
                ]
            );
        }
    }

    private function loanTypeId(string $code): int
    {
        $table = (new LoanType())->getTable();

        $column = collect(['loan_code', 'code', 'type_code', 'loan_type_code'])
            ->first(fn ($c) => Schema::hasColumn($table, $c));

        abort_unless($column, 500, "Could not find the code column on {$table}.");

        return LoanType::where($column, $code)->firstOrFail()->id;
    }
}