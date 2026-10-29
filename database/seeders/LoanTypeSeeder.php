<?php

namespace Database\Seeders;

use App\Models\LoanType;
use Illuminate\Database\Seeder;

class LoanTypeSeeder extends Seeder
{
    public function run(): void
    {
        $loanTypes = [
            [
                'loan_code' => 'SSS-SL',
                'loan_name' => 'SSS Salary Loan',
                'provider' => 'SSS',
                'status' => true,
            ],
            [
                'loan_code' => 'SSS-CL',
                'loan_name' => 'SSS Calamity Loan',
                'provider' => 'SSS',
                'status' => true,
            ],
            [
                'loan_code' => 'HDMF-MPL',
                'loan_name' => 'Pag-IBIG Multi-Purpose Loan',
                'provider' => 'Pag-IBIG',
                'status' => true,
            ],
            [
                'loan_code' => 'HDMF-CL',
                'loan_name' => 'Pag-IBIG Calamity Loan',
                'provider' => 'Pag-IBIG',
                'status' => true,
            ],
            [
                'loan_code' => 'COMP-LOAN',
                'loan_name' => 'Company Loan',
                'provider' => 'Company',
                'status' => true,
            ],
            [
                'loan_code' => 'CASH-ADV',
                'loan_name' => 'Salary Cash Advance',
                'provider' => 'Company',
                'status' => true,
            ],
        ];

        foreach ($loanTypes as $loanType) {
            LoanType::updateOrCreate(
                ['loan_code' => $loanType['loan_code']],
                [
                    'loan_name' => $loanType['loan_name'],
                    'provider' => $loanType['provider'],
                    'status' => $loanType['status'],
                ]
            );
        }
    }
}
