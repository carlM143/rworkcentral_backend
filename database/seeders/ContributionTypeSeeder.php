<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\ContributionChangeLog;
use App\Models\ContributionDeductionSetting;
use App\Models\ContributionRateBracket;
use App\Models\ContributionRateSchedule;
use App\Models\ContributionType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ContributionTypeSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $types = [
                [
                    'contribution_code' => 'SSS',
                    'contribution_name' => 'Social Security System',
                    'provider' => 'SSS',
                    'is_status' => true,
                ],
                [
                    'contribution_code' => 'PHIC',
                    'contribution_name' => 'PhilHealth Contribution',
                    'provider' => 'PhilHealth',
                    'is_status' => true,
                ],
                [
                    'contribution_code' => 'HDMF',
                    'contribution_name' => 'Pag-IBIG Fund Contribution',
                    'provider' => 'Pag-IBIG',
                    'is_status' => true,
                ],
            ];

            $typeMap = [];
            foreach ($types as $type) {
                $record = ContributionType::updateOrCreate(
                    ['contribution_code' => $type['contribution_code']],
                    [
                        'contribution_name' => $type['contribution_name'],
                        'provider' => $type['provider'],
                        'is_status' => $type['is_status'],
                    ]
                );
                $typeMap[$type['contribution_code']] = $record;
            }

            // Remove stale test types (e.g. UNION) that payroll has never used.
            ContributionType::whereNotIn('contribution_code', ['SSS', 'PHIC', 'HDMF'])
                ->whereDoesntHave('payrollContributions')
                ->get()
                ->each(function (ContributionType $stale) {
                    if (Schema::hasTable('contribution_change_logs')) {
                        $stale->changeLogs()->delete();
                    }
                    $scheduleIds = $stale->rateSchedules()->pluck('id');
                    if ($scheduleIds->isNotEmpty()) {
                        ContributionRateBracket::whereIn('contribution_rate_schedule_id', $scheduleIds)->delete();
                    }
                    $stale->deductionSettings()->delete();
                    $stale->rateSchedules()->delete();
                    $stale->delete();
                });

            $sssType = $typeMap['SSS'];
            $phicType = $typeMap['PHIC'];
            $hdmfType = $typeMap['HDMF'];

            // Clear schedules and brackets not yet used by any payroll.
            foreach ([$sssType, $phicType, $hdmfType] as $cType) {
                $existingSchedules = $cType->rateSchedules()->whereDoesntHave('payrollContributions')->get();
                foreach ($existingSchedules as $sched) {
                    if (Schema::hasTable('contribution_change_logs')) {
                        ContributionChangeLog::where('contribution_rate_schedule_id', $sched->id)
                            ->update(['contribution_rate_schedule_id' => null]);
                    }
                    $sched->brackets()->delete();
                    $sched->delete();
                }
            }

            $now = now();

            // ---------------------------------------------------------
            // 1. SSS — Circular No. 2024-006 (effective January 2025)
            // 15% total (5% employee, 10% employer), MSC ₱5,000 – ₱35,000
            // EC: ₱10 below MSC ₱15,000, ₱30 from MSC ₱15,000
            // ---------------------------------------------------------
            $sssSchedule = ContributionRateSchedule::create([
                'contribution_type_id' => $sssType->id,
                'calculation_method' => 'bracket',
                'salary_basis' => 'Monthly Basic Salary',
                'employee_rate' => null,
                'employer_rate' => null,
                'employee_amount' => null,
                'employer_amount' => null,
                'frequency' => null,
                'minimum_salary_base' => 5000.00,
                'maximum_salary_base' => 35000.00,
                'effective_from' => '2025-01-01',
                'effective_to' => null,
                'is_status' => true,
            ]);

            $sssBrackets = [];
            for ($msc = 5000; $msc <= 35000; $msc += 500) {
                $sssBrackets[] = [
                    'contribution_rate_schedule_id' => $sssSchedule->id,
                    'salary_from' => $msc === 5000 ? 0.00 : (float) ($msc - 250),
                    'salary_to' => $msc === 35000 ? null : (float) ($msc + 249.99),
                    'monthly_salary_credit' => (float) $msc,
                    'employee_rate' => 5.00000,
                    'employer_rate' => 10.00000,
                    'employee_amount' => round($msc * 0.05, 2),
                    'employer_amount' => round($msc * 0.10, 2),
                    'employer_ec_amount' => $msc >= 15000 ? 30.00 : 10.00,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            ContributionRateBracket::insert($sssBrackets);

            // ---------------------------------------------------------
            // 2. PhilHealth (PHIC) — percentage of monthly basic salary
            // 5% total (2.5% employee, 2.5% employer)
            // Floor ₱10,000 | Ceiling ₱100,000 — confirm with the latest PhilHealth circular
            // ---------------------------------------------------------
            ContributionRateSchedule::create([
                'contribution_type_id' => $phicType->id,
                'calculation_method' => 'percentage',
                'salary_basis' => 'Monthly Basic Salary',
                'employee_rate' => 2.5000,
                'employer_rate' => 2.5000,
                'employee_amount' => null,
                'employer_amount' => null,
                'frequency' => null,
                'minimum_salary_base' => 10000.00,
                'maximum_salary_base' => 100000.00,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
                'is_status' => true,
            ]);

            // ---------------------------------------------------------
            // 3. Pag-IBIG / HDMF
            // ₱1,500 and below: 1% employee, 2% employer (computed from salary)
            // Above ₱1,500: 2% / 2% of max fund salary ₱10,000 = ₱200 / ₱200
            // ---------------------------------------------------------
            $hdmfSchedule = ContributionRateSchedule::create([
                'contribution_type_id' => $hdmfType->id,
                'calculation_method' => 'bracket',
                'salary_basis' => 'Monthly Basic Salary',
                'employee_rate' => null,
                'employer_rate' => null,
                'employee_amount' => null,
                'employer_amount' => null,
                'frequency' => null,
                'minimum_salary_base' => 0.00,
                'maximum_salary_base' => 10000.00,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
                'is_status' => true,
            ]);

            ContributionRateBracket::insert([
                [
                    'contribution_rate_schedule_id' => $hdmfSchedule->id,
                    'salary_from' => 0.00,
                    'salary_to' => 1500.00,
                    'monthly_salary_credit' => null,
                    'employee_rate' => 1.00000,
                    'employer_rate' => 2.00000,
                    'employee_amount' => null,
                    'employer_amount' => null,
                    'employer_ec_amount' => 0.00,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'contribution_rate_schedule_id' => $hdmfSchedule->id,
                    'salary_from' => 1500.01,
                    'salary_to' => null,
                    'monthly_salary_credit' => 10000.00,
                    'employee_rate' => 2.00000,
                    'employer_rate' => 2.00000,
                    'employee_amount' => 200.00,
                    'employer_amount' => 200.00,
                    'employer_ec_amount' => 0.00,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            // ---------------------------------------------------------
            // 4. Deduction settings — which cutoff deducts each contribution
            // 1st cutoff: SSS | 2nd cutoff: Pag-IBIG and PhilHealth
            // ---------------------------------------------------------
            $company = Company::query()->first();

            if ($company) {
                ContributionDeductionSetting::query()
                    ->where('company_id', $company->id)
                    ->delete();

                $settings = [
                    [$sssType, 100.00, 0.00],
                    [$hdmfType, 0.00, 100.00],
                    [$phicType, 0.00, 100.00],
                ];

                foreach ($settings as [$cType, $first, $second]) {
                    ContributionDeductionSetting::create([
                        'company_id' => $company->id,
                        'contribution_type_id' => $cType->id,
                        'first_cutoff_percent' => $first,
                        'second_cutoff_percent' => $second,
                        'effective_from' => '2026-01-01',
                        'effective_to' => null,
                        'is_status' => true,
                    ]);
                }
            }

        });
    }
}