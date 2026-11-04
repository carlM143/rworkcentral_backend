<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\ContributionDeductionSetting;
use App\Models\ContributionRateBracket;
use App\Models\ContributionRateSchedule;
use App\Models\ContributionType;
use App\Models\Department;
use App\Models\SalaryType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

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

            // Remove any stale test types such as UNION if unused by payroll
            ContributionType::whereNotIn('contribution_code', ['SSS', 'PHIC', 'HDMF'])
                ->whereDoesntHave('payrollContributions')
                ->get()
                ->each(function (ContributionType $stale) {
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

            // Clear existing brackets and schedules for SSS, PHIC, HDMF if not tied to finalized payroll contributions
            foreach ([$sssType, $phicType, $hdmfType] as $cType) {
                $existingSchedules = $cType->rateSchedules()->whereDoesntHave('payrollContributions')->get();
                foreach ($existingSchedules as $sched) {
                    $sched->brackets()->delete();
                    $sched->delete();
                }
            }

            // ---------------------------------------------------------
            // 1. SSS — Circular No. 2024-006 (Effective January 2025)
            // Rate: 15% (5% Employee, 10% Employer)
            // Min MSC: ₱5,000.00 | Max MSC: ₱35,000.00 (₱20k Regular SS + ₱15k MPF)
            // ---------------------------------------------------------
            $sssSchedule = ContributionRateSchedule::create([
                'contribution_type_id' => $sssType->id,
                'calculation_method' => 'bracket',
                'salary_basis' => 'monthly_salary_credit',
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
            $now = now();
            for ($msc = 5000; $msc <= 35000; $msc += 500) {
                $salaryFrom = $msc === 5000 ? 0.00 : (float) ($msc - 250);
                $salaryTo = $msc === 35000 ? null : (float) ($msc + 249.99);
                $ecAmount = $msc >= 15000 ? 30.00 : 10.00;

                $sssBrackets[] = [
                    'contribution_rate_schedule_id' => $sssSchedule->id,
                    'salary_from' => $salaryFrom,
                    'salary_to' => $salaryTo,
                    'monthly_salary_credit' => (float) $msc,
                    'employee_rate' => 5.00000,
                    'employer_rate' => 10.00000,
                    'employee_amount' => round($msc * 0.05, 2),
                    'employer_amount' => round($msc * 0.10, 2),
                    'employer_ec_amount' => $ecAmount,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            ContributionRateBracket::insert($sssBrackets);

            // ---------------------------------------------------------
            // 2. PhilHealth (PHIC) — 2026 Contribution Table
            // Rate: 5.5% Total (2.75% Employee Share, 2.75% Employer Share)
            // Floor: ₱10,000.00 (₱275 EE / ₱275 ER = ₱550 Total)
            // Ceiling: ₱50,000.00 (₱1,375 EE / ₱1,375 ER = ₱2,750 Total)
            // ---------------------------------------------------------
            ContributionRateSchedule::create([
                'contribution_type_id' => $phicType->id,
                'calculation_method' => 'percentage',
                'salary_basis' => 'monthly_basic_salary',
                'employee_rate' => 2.75000,
                'employer_rate' => 2.75000,
                'employee_amount' => null,
                'employer_amount' => null,
                'frequency' => null,
                'minimum_salary_base' => 10000.00,
                'maximum_salary_base' => 50000.00,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
                'is_status' => true,
            ]);

            // ---------------------------------------------------------
            // 3. Pag-IBIG / HDMF — 2026 Table
            // Tier 1: ₱0.00 – ₱1,500.00 -> 1.00% EE, 2.00% ER (Max ₱200 EE / ₱200 ER)
            // Tier 2: ₱1,500.01 – ₱999,999,999.99 -> 2.00% EE, 2.00% ER (Max ₱200 EE / ₱200 ER)
            // ---------------------------------------------------------
            $hdmfSchedule = ContributionRateSchedule::create([
                'contribution_type_id' => $hdmfType->id,
                'calculation_method' => 'bracket',
                'salary_basis' => 'monthly_fund_salary',
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
                    'monthly_salary_credit' => 1500.00,
                    'employee_rate' => 1.00000,
                    'employer_rate' => 2.00000,
                    'employee_amount' => 200.00,
                    'employer_amount' => 200.00,
                    'employer_ec_amount' => 0.00,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'contribution_rate_schedule_id' => $hdmfSchedule->id,
                    'salary_from' => 1500.01,
                    'salary_to' => 999999999.99,
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
            // 4. Contribution Deduction Settings
            // Department: R Web Solutions | Salary Type: Semi-Monthly
            // - 1st Deduction in Month: SSS (100% First Cutoff, 0% Second Cutoff)
            // - 2nd Deduction in Month: Pag-IBIG / HDMF (0% First Cutoff, 100% Second Cutoff)
            // - 2nd Deduction in Month: PhilHealth / PHIC (0% First Cutoff, 100% Second Cutoff)
            // ---------------------------------------------------------
            $company = Company::query()->first();
            if ($company) {
                $rWebDept = Department::query()
                    ->where('company_id', $company->id)
                    ->where(function ($q) {
                        $q->where('department_code', 'RWS')
                            ->orWhere('department_name', 'R Web Solutions');
                    })
                    ->first();

                $monthly = SalaryType::query()
                    ->where('type_name', 'Monthly')
                    ->first();

                $semiMonthly = SalaryType::query()
                    ->where('type_name', 'Semi-Monthly')
                    ->first();

                $weekly = SalaryType::query()
                    ->where('type_name', 'Weekly')
                    ->first();

                $techDept = Department::query()
                    ->where('company_id', $company->id)
                    ->where(function ($q) {
                        $q->where('department_code', 'TECH')
                            ->orWhere('department_name', 'Technicians');
                    })
                    ->first();

                ContributionDeductionSetting::query()
                    ->where('company_id', $company->id)
                    ->delete();

                // A. Monthly Salary Type — Full Monthly Deduction (100% once a month for SSS, Pag-IBIG & PhilHealth)
                $monthlySettings = [
                    $sssType->id,
                    $hdmfType->id,
                    $phicType->id,
                ];

                foreach ($monthlySettings as $cTypeId) {
                    ContributionDeductionSetting::create([
                        'company_id' => $company->id,
                        'department_id' => null,
                        'salary_type_id' => $monthly?->id,
                        'contribution_type_id' => $cTypeId,
                        'deduction_schedule' => 'monthly_full',
                        'first_cutoff_percent' => 100.00,
                        'second_cutoff_percent' => 0.00,
                        'effective_from' => '2026-01-01',
                        'effective_to' => null,
                        'is_status' => true,
                    ]);
                }

                // B. R Web Solutions — Semi-Monthly (1st Deduction = SSS, 2nd Deduction = Pag-IBIG & PhilHealth)
                $rwsSettings = [
                    [
                        'contribution_type_id' => $sssType->id,
                        'deduction_schedule' => 'first_deduction',
                        'first_cutoff_percent' => 100.00,
                        'second_cutoff_percent' => 0.00,
                    ],
                    [
                        'contribution_type_id' => $hdmfType->id,
                        'deduction_schedule' => 'second_deduction',
                        'first_cutoff_percent' => 0.00,
                        'second_cutoff_percent' => 100.00,
                    ],
                    [
                        'contribution_type_id' => $phicType->id,
                        'deduction_schedule' => 'second_deduction',
                        'first_cutoff_percent' => 0.00,
                        'second_cutoff_percent' => 100.00,
                    ],
                ];

                foreach ($rwsSettings as $setting) {
                    ContributionDeductionSetting::create([
                        'company_id' => $company->id,
                        'department_id' => $rWebDept?->id,
                        'salary_type_id' => $semiMonthly?->id,
                        'contribution_type_id' => $setting['contribution_type_id'],
                        'deduction_schedule' => $setting['deduction_schedule'],
                        'first_cutoff_percent' => $setting['first_cutoff_percent'],
                        'second_cutoff_percent' => $setting['second_cutoff_percent'],
                        'effective_from' => '2026-01-01',
                        'effective_to' => null,
                        'is_status' => true,
                    ]);
                }

                // B. Technicians — Weekly (Every Saturday of the Week)
                // 2nd Saturday = SSS (100%) | 4th Saturday = Pag-IBIG (100%) & PhilHealth (100%)
                $techSettings = [
                    [
                        'contribution_type_id' => $sssType->id,
                        'deduction_schedule' => 'weekly_2nd_saturday',
                        'first_cutoff_percent' => 100.00,
                        'second_cutoff_percent' => 0.00,
                        'week_1_percent' => 0.00,
                        'week_2_percent' => 100.00,
                        'week_3_percent' => 0.00,
                        'week_4_percent' => 0.00,
                    ],
                    [
                        'contribution_type_id' => $hdmfType->id,
                        'deduction_schedule' => 'weekly_4th_saturday',
                        'first_cutoff_percent' => 0.00,
                        'second_cutoff_percent' => 100.00,
                        'week_1_percent' => 0.00,
                        'week_2_percent' => 0.00,
                        'week_3_percent' => 0.00,
                        'week_4_percent' => 100.00,
                    ],
                    [
                        'contribution_type_id' => $phicType->id,
                        'deduction_schedule' => 'weekly_4th_saturday',
                        'first_cutoff_percent' => 0.00,
                        'second_cutoff_percent' => 100.00,
                        'week_1_percent' => 0.00,
                        'week_2_percent' => 0.00,
                        'week_3_percent' => 0.00,
                        'week_4_percent' => 100.00,
                    ],
                ];

                foreach ($techSettings as $setting) {
                    ContributionDeductionSetting::create([
                        'company_id' => $company->id,
                        'department_id' => $techDept?->id,
                        'salary_type_id' => $weekly?->id,
                        'contribution_type_id' => $setting['contribution_type_id'],
                        'deduction_schedule' => $setting['deduction_schedule'],
                        'first_cutoff_percent' => $setting['first_cutoff_percent'],
                        'second_cutoff_percent' => $setting['second_cutoff_percent'],
                        'week_1_percent' => $setting['week_1_percent'],
                        'week_2_percent' => $setting['week_2_percent'],
                        'week_3_percent' => $setting['week_3_percent'],
                        'week_4_percent' => $setting['week_4_percent'],
                        'effective_from' => '2026-01-01',
                        'effective_to' => null,
                        'is_status' => true,
                    ]);
                }
            }
        });
    }
}
