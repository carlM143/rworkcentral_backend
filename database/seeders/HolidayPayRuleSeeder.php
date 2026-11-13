<?php

namespace Database\Seeders;

use App\Models\HolidayPayRule;
use App\Models\HolidayType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class HolidayPayRuleSeeder extends Seeder
{
    /**
     * Philippine holiday pay rules.
     *
     * Rates represent percentages of the applicable daily rate.
     *
     * IMPORTANT:
     * - "all" applies to all employee classifications.
     * - "daily_paid" applies only to daily-paid employees.
     * - Monthly-paid employees may already have regular holiday
     *   pay included in their monthly salary.
     * - Eligibility and salary inclusion must be checked during
     *   payroll computation, not determined by applies_to alone.
     *
     * Overtime rates are percentages of the hourly rate.
     *
     * Confirm applicable DOLE advisories and holiday declarations
     * before using these rules in production.
     */
    private const TYPES = [

        'regular' => [
            'name' => 'Regular Holiday',
            'requires_prior_day_present' => true,

            'rules' => [
                ['unworked', 100, 'daily_paid'],
                ['worked', 200, 'all'],
                ['worked_overtime', 260, 'all'],
                ['rest_day_worked', 260, 'all'],
                ['rest_day_overtime', 338, 'all'],
            ],
        ],

        'special_non_working' => [
            'name' => 'Special Non-Working Holiday',
            'requires_prior_day_present' => false,

            'rules' => [
                ['worked', 130, 'all'],
                ['worked_overtime', 169, 'all'],
                ['rest_day_worked', 150, 'all'],
                ['rest_day_overtime', 195, 'all'],
            ],
        ],

        'special_working' => [
            'name' => 'Special Working Holiday',
            'requires_prior_day_present' => false,

            'rules' => [
                ['worked', 100, 'all'],
                ['worked_overtime', 125, 'all'],
                ['rest_day_worked', 130, 'all'],
                ['rest_day_overtime', 169, 'all'],
            ],
        ],

        'double' => [
            'name' => 'Double Holiday',
            'requires_prior_day_present' => true,

            'rules' => [
                ['unworked', 200, 'daily_paid'],
                ['worked', 300, 'all'],
                ['worked_overtime', 390, 'all'],
                ['rest_day_worked', 390, 'all'],
                ['rest_day_overtime', 507, 'all'],
            ],
        ],
    ];

    private const EFFECTIVE_FROM = '2026-01-01';

    public function run(): void
    {
        DB::transaction(function () {

            foreach (self::TYPES as $code => $config) {

                $type = HolidayType::query()->firstOrCreate(
                    ['type_code' => $code],
                    [
                        'type_name' => $config['name'],
                        'status' => true,
                    ]
                );

                $type->update([
                    'requires_prior_day_present' =>
                        $config['requires_prior_day_present'],
                ]);

                foreach ($config['rules'] as [$scenario, $rate, $appliesTo]) {

                    HolidayPayRule::query()->updateOrCreate(
                        [
                            'holiday_type_id' => $type->id,
                            'scenario' => $scenario,
                            'applies_to' => $appliesTo,
                            'effective_from' => self::EFFECTIVE_FROM,
                        ],
                        [
                            'rate_percent' => $rate,
                            'effective_to' => null,
                            'is_status' => true,
                        ]
                    );
                }
            }
        });
    }
}
