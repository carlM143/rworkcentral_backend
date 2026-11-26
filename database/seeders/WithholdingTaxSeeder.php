<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;


class WithholdingTaxSeeder extends Seeder
{
    /** [over, up_to, base_tax, rate%] */
    private const TABLES = [
        'daily' => [
            [0, 685, 0, 0],
            [685, 1095, 0, 15],
            [1096, 2191, 61.65, 20],
            [2192, 5478, 280.85, 25],
            [5479, 21917, 1102.60, 30],
            [21918, null, 6034.30, 35],
        ],
        'weekly' => [
            [0, 4808, 0, 0],
            [4808, 7691, 0, 15],
            [7692, 15384, 432.60, 20],
            [15385, 38461, 1971.20, 25],
            [38462, 153845, 7740.45, 30],
            [153846, null, 42355.65, 35],
        ],
        'semi_monthly' => [
            [0, 10417, 0, 0],
            [10417, 16666, 0, 15],
            [16667, 33332, 937.50, 20],
            [33333, 83332, 4270.70, 25],
            [83333, 333332, 16770.70, 30],
            [333333, null, 91770.70, 35],
        ],
        'monthly' => [
            [0, 20833, 0, 0],
            [20833, 33332, 0, 15],
            [33333, 66666, 1875.00, 20],
            [66667, 166666, 8541.80, 25],
            [166667, 666666, 33541.80, 30],
            [666667, null, 183541.80, 35],
        ],
    ];

    private const LABELS = [
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'semi_monthly' => 'Semi-Monthly',
        'monthly' => 'Monthly',
    ];

    public function run(): void
    {
        DB::transaction(function () {
            foreach (self::TABLES as $frequency => $brackets) {
                $exists = DB::table('withholding_tax_tables')
                    ->where('frequency', $frequency)
                    ->where('effective_from', '2023-01-01')
                    ->exists();

                if ($exists) {
                    continue;
                }

                $tableId = DB::table('withholding_tax_tables')->insertGetId([
                    'name' => 'BIR '.self::LABELS[$frequency].' Withholding Tax Table (2023 onwards)',
                    'frequency' => $frequency,
                    'effective_from' => '2023-01-01',
                    'effective_to' => null,
                    'is_active' => true,
                    'reference' => 'TRAIN law · RR 11-2018, Annex E (2023 onwards)',
                    'notes' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($brackets as $i => [$over, $upTo, $base, $rate]) {
                    DB::table('withholding_tax_brackets')->insert([
                        'withholding_tax_table_id' => $tableId,
                        'sort' => $i + 1,
                        'over' => $over,
                        'up_to' => $upTo,
                        'base_tax' => $base,
                        'rate' => $rate,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            if (! DB::table('payroll_tax_settings')->exists()) {
                DB::table('payroll_tax_settings')->insert([
                    'apply_mwe_exemption' => true,
                    'main_allowance_taxable' => true,
                    'non_taxable_allowances' => json_encode([]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }
}
