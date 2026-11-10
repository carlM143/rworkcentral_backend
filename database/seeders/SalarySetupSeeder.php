<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\SalarySetup;
use App\Models\SalaryType;
use Illuminate\Database\Seeder;

class SalarySetupSeeder extends Seeder
{
    public function run(): void
    {
        $types = SalaryType::pluck('id', 'type_name');

        $setups = [
            // Davao — R Web Solutions
            ['emp_id' => 1001, 'type' => 'Monthly', 'basic' => 22000, 'allowance' => 1000, 'bank' => 'BDO', 'account' => '0012345678901'],
            ['emp_id' => 1002, 'type' => 'Monthly', 'basic' => 28000, 'allowance' => 1500, 'bank' => 'BPI', 'account' => '3456789012'],

            // Davao — Airpro
            ['emp_id' => 1003, 'type' => 'Monthly', 'basic' => 30000, 'allowance' => 2000, 'bank' => 'Metrobank', 'account' => '1234567890123'],
            ['emp_id' => 1004, 'type' => 'Daily', 'basic' => 750, 'allowance' => 1000, 'bank' => 'Landbank', 'account' => '9876543210'],
            ['emp_id' => 1005, 'type' => 'Daily', 'basic' => 610, 'allowance' => 500, 'bank' => null, 'account' => null],

            // Manila — R Web Solutions
            ['emp_id' => 1006, 'type' => 'Monthly', 'basic' => 35000, 'allowance' => 2000, 'bank' => 'BDO', 'account' => '0098765432109'],
            ['emp_id' => 1007, 'type' => 'Monthly', 'basic' => 25000, 'allowance' => 1000, 'bank' => 'UnionBank', 'account' => '109876543210'],
            ['emp_id' => 1008, 'type' => 'Monthly', 'basic' => 60000, 'allowance' => 5000, 'bank' => 'BPI', 'account' => '8765432109'],

            // Manila — Airpro
            ['emp_id' => 1009, 'type' => 'Monthly', 'basic' => 24000, 'allowance' => 1000, 'bank' => 'Security Bank', 'account' => '0000123456789'],
            ['emp_id' => 1010, 'type' => 'Daily', 'basic' => 695, 'allowance' => 500, 'bank' => 'Metrobank', 'account' => '3210987654321'],
        ];

        foreach ($setups as $setup) {
            $employee = Employee::where('emp_id', $setup['emp_id'])->firstOrFail();

            SalarySetup::updateOrCreate(
                ['employee_id' => $employee->id],
                [
                    'salary_type_id' => $types[$setup['type']] ?? null,
                    'basic_salary' => $setup['basic'],
                    'allowance' => $setup['allowance'],
                    'bank_name' => $setup['bank'],
                    'account_name' => $setup['bank']
                        ? collect([$employee->firstname, $employee->middlename, $employee->lastname, $employee->suffix])
                            ->filter()
                            ->implode(' ')
                        : null,
                    'account_number' => $setup['account'],
                ]
            );
        }
    }
}