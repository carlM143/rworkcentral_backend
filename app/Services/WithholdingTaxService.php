<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WithholdingTaxService
{
  
    private array $tables = [];

    private ?object $settings = null;

    public function __construct(private readonly WageRateService $wages)
    {
    }

    public function settings(): object
    {
        if ($this->settings) {
            return $this->settings;
        }

        $row = DB::table('payroll_tax_settings')->first();

        return $this->settings = (object) [
            'apply_mwe_exemption' => $row ? (bool) $row->apply_mwe_exemption : true,
            'main_allowance_taxable' => $row ? (bool) $row->main_allowance_taxable : true,
            'non_taxable_allowances' => collect(json_decode($row->non_taxable_allowances ?? '[]', true) ?: [])
                ->map(fn ($name) => mb_strtolower(trim((string) $name)))
                ->filter()
                ->values()
                ->all(),
        ];
    }

    /**
     * Table in force for a frequency on a date (with its brackets), or null.
     */
    public function tableFor(string $frequency, Carbon|string $date): ?array
    {
        $date = Carbon::parse($date)->toDateString();
        $key = "{$frequency}|{$date}";

        if (array_key_exists($key, $this->tables)) {
            return $this->tables[$key];
        }

        $table = DB::table('withholding_tax_tables')
            ->where('frequency', $frequency)
            ->where('is_active', true)
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->orderByDesc('effective_from')
            ->first();

        if (! $table) {
            return $this->tables[$key] = null;
        }

        $brackets = DB::table('withholding_tax_brackets')
            ->where('withholding_tax_table_id', $table->id)
            ->orderBy('over')
            ->get(['over', 'up_to', 'base_tax', 'rate'])
            ->map(fn ($b) => [
                'over' => (float) $b->over,
                'up_to' => $b->up_to === null ? null : (float) $b->up_to,
                'base_tax' => (float) $b->base_tax,
                'rate' => (float) $b->rate,
            ])
            ->all();

        return $this->tables[$key] = [
            'id' => (int) $table->id,
            'name' => $table->name,
            'frequency' => $table->frequency,
            'brackets' => $brackets,
        ];
    }

    /**
     * Tax on an amount of taxable compensation using a table.
     *
     * @return array{tax: float, bracket: array|null}
     */
    public function taxFromTable(?array $table, float $taxable): array
    {
        if (! $table || $taxable <= 0) {
            return ['tax' => 0.0, 'bracket' => null];
        }

        $bracket = collect($table['brackets'])
            ->filter(fn ($b) => $taxable >= $b['over'])
            ->sortByDesc('over')
            ->first();

        if (! $bracket) {
            return ['tax' => 0.0, 'bracket' => null];
        }

        $tax = $bracket['base_tax'] + ($taxable - $bracket['over']) * $bracket['rate'] / 100;

        return ['tax' => round(max(0, $tax), 2), 'bracket' => $bracket];
    }

    /**
     * Minimum wage earner: daily rate not above the regional minimum in force
     * for the employee's work location (Compensation › Regional Rates).
     */
    public function isMinimumWageEarner(int $employeeId, float $dailyRate, Carbon|string $date): array
    {
        $location = DB::table('employments')
            ->leftJoin('locations', 'locations.id', '=', 'employments.location_id')
            ->where('employments.employee_id', $employeeId)
            ->orderByDesc('employments.id')
            ->first(['locations.region_code', 'locations.wage_classification']);

        if (! $location?->region_code) {
            return ['mwe' => false, 'minimum' => null];
        }

        $rate = $this->wages->currentRate(
            $location->region_code,
            $location->wage_classification ?: 'non_agriculture',
            Carbon::parse($date),
        );

        $minimum = $rate ? (float) $rate->daily_rate : null;

        return [
            'mwe' => $minimum !== null && $dailyRate > 0 && $dailyRate <= $minimum + 0.009,
            'minimum' => $minimum,
        ];
    }

    /**
     * Withholding tax of one payroll.
     *
     * @param  array{
     *   employee_id:int, frequency:string, date:Carbon|string, daily_rate:float,
     *   basic:float, overtime:float, holiday:float,
     *   main_allowance:float, extra_allowances:array<int, array{allowance_name:string, amount:float}>,
     *   attendance_deductions:float, contributions:float
     * }  $pay
     * @return array{tax: float, detail: array}
     */
    public function compute(array $pay): array
    {
        $settings = $this->settings();

        // Allowances: taxable unless listed as non-taxable (de minimis).
        $allowances = collect();
        if ($pay['main_allowance'] > 0) {
            $allowances->push([
                'name' => 'Allowance',
                'amount' => round($pay['main_allowance'], 2),
                'taxable' => $settings->main_allowance_taxable,
            ]);
        }
        foreach ($pay['extra_allowances'] as $line) {
            $name = (string) ($line['allowance_name'] ?? 'Allowance');
            $allowances->push([
                'name' => $name,
                'amount' => round((float) $line['amount'], 2),
                'taxable' => ! in_array(mb_strtolower(trim($name)), $settings->non_taxable_allowances, true),
            ]);
        }

        $taxableAllowances = (float) $allowances->where('taxable', true)->sum('amount');
        $nonTaxableAllowances = (float) $allowances->where('taxable', false)->sum('amount');

        $mwe = ['mwe' => false, 'minimum' => null];
        if ($settings->apply_mwe_exemption) {
            $mwe = $this->isMinimumWageEarner($pay['employee_id'], $pay['daily_rate'], $pay['date']);
        }

        $compensation = $pay['basic'] + $pay['overtime'] + $pay['holiday'] - $pay['attendance_deductions'];

        if ($mwe['mwe']) {
            // MWE: statutory minimum wage, overtime and holiday pay are exempt.
            $exemptPay = max(0, $compensation);
            $taxable = max(0, $taxableAllowances);
        } else {
            $exemptPay = 0.0;
            $taxable = max(0, $compensation + $taxableAllowances - $pay['contributions']);
        }

        [$tax, $bracket, $table] = $this->taxForFrequency($pay['frequency'], $pay['date'], $taxable);

        return [
            'tax' => $tax,
            'detail' => [
                'taxable_compensation' => round($taxable, 2),
                'minimum_wage_earner' => $mwe['mwe'],
                'regional_minimum' => $mwe['minimum'],
                'exempt_mwe_pay' => round($exemptPay, 2),
                'non_taxable_contributions' => round($pay['contributions'], 2),
                'non_taxable_allowances' => round($nonTaxableAllowances, 2),
                'allowances' => $allowances->values()->all(),
                'table' => $table ? ['id' => $table['id'], 'name' => $table['name'], 'frequency' => $table['frequency']] : null,
                'bracket' => $bracket,
                'withholding_tax' => $tax,
                'note' => $table ? null : 'No withholding tax table for this pay frequency and date.',
            ],
        ];
    }

    /**
     * Tax for a pay frequency. Bi-weekly pay uses the weekly table twice.
     *
     * @return array{0: float, 1: array|null, 2: array|null}
     */
    public function taxForFrequency(string $frequency, Carbon|string $date, float $taxable): array
    {
        if ($frequency === 'bi_weekly') {
            $table = $this->tableFor('weekly', $date);
            $half = $this->taxFromTable($table, $taxable / 2);

            return [round($half['tax'] * 2, 2), $half['bracket'], $table];
        }

        $table = $this->tableFor($frequency, $date);
        $result = $this->taxFromTable($table, $taxable);

        return [$result['tax'], $result['bracket'], $table];
    }

    /** Distinct allowance names in use, for the settings page. */
    public function allowanceNames(): Collection
    {
        return DB::table('employee_allowance')
            ->select('allowance_name')
            ->distinct()
            ->orderBy('allowance_name')
            ->pluck('allowance_name');
    }
}
