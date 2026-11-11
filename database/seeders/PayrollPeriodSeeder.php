<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\PayrollPeriod;
use App\Models\User;
use Illuminate\Database\Seeder;

class PayrollPeriodSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::where('company_name', 'R Work Central')->firstOrFail();
        $admin = User::where('email', 'hr@rworkcentral.test')->firstOrFail();

        $periods = [
            [
                'period_name' => 'November 1–15, 2026',
                'cutoff_number' => 1,
                'pay_period_start' => '2026-11-01',
                'pay_period_end' => '2026-11-10',
                'cutoff_start' => '2026-10-26',
                'cutoff_end' => '2026-11-04',
                'payment_date' => '2026-11-30',
            ],
            [
                'period_name' => 'November 16–30, 2026',
                'cutoff_number' => 2,
                'pay_period_start' => '2026-11-16',
                'pay_period_end' => '2026-11-30',
                'cutoff_start' => '2026-11-08',
                'cutoff_end' => '2026-11-22',
                'payment_date' => '2026-11-30',
            ],
        ];

        foreach ($periods as $period) {
            $exists = PayrollPeriod::where('company_id', $company->id)
                ->whereDate('pay_period_start', '<=', $period['pay_period_end'])
                ->whereDate('pay_period_end', '>=', $period['pay_period_start'])
                ->exists();

            if ($exists) {
                continue;
            }

            PayrollPeriod::create([
                ...$period,
                'user_id' => $admin->id,
                'company_id' => $company->id,
                'status' => 'draft',
            ]);
        }
    }
}