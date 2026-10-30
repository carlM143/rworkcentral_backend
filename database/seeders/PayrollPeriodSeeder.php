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
                'period_name' => 'October 1–15, 2026',
                'cutoff_number' => 1,
                'cutoff_start' => '2026-10-01',
                'cutoff_end' => '2026-10-15',
                'payment_date' => '2026-10-20',
            ],
            [
                'period_name' => 'October 16–31, 2026',
                'cutoff_number' => 2,
                'cutoff_start' => '2026-10-16',
                'cutoff_end' => '2026-10-31',
                'payment_date' => '2026-11-05',
            ],
            [
                'period_name' => 'November 1–15, 2026',
                'cutoff_number' => 1,
                'cutoff_start' => '2026-11-01',
                'cutoff_end' => '2026-11-15',
                'payment_date' => '2026-11-20',
            ],
            [
                'period_name' => 'November 16–30, 2026',
                'cutoff_number' => 2,
                'cutoff_start' => '2026-11-16',
                'cutoff_end' => '2026-11-30',
                'payment_date' => '2026-12-05',
            ],
            [
                'period_name' => 'December 1–15, 2026',
                'cutoff_number' => 1,
                'cutoff_start' => '2026-12-01',
                'cutoff_end' => '2026-12-15',
                'payment_date' => '2026-12-20',
            ],
            [
                'period_name' => 'December 16–31, 2026',
                'cutoff_number' => 2,
                'cutoff_start' => '2026-12-16',
                'cutoff_end' => '2026-12-31',
                'payment_date' => '2027-01-05',
            ],
        ];

        foreach ($periods as $period) {
            $exists = PayrollPeriod::where('company_id', $company->id)
                ->whereDate('cutoff_start', '<=', $period['cutoff_end'])
                ->whereDate('cutoff_end', '>=', $period['cutoff_start'])
                ->exists();

            if ($exists) {
                continue;
            }

            PayrollPeriod::create([
                'user_id' => $admin->id,
                'company_id' => $company->id,
                'cutoff_number' => $period['cutoff_number'],
                'period_name' => $period['period_name'],
                'cutoff_start' => $period['cutoff_start'],
                'cutoff_end' => $period['cutoff_end'],
                'payment_date' => $period['payment_date'],
                'status' => 'draft',
            ]);
        }
    }
}
