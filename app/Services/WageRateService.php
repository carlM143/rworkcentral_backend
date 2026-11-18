<?php

namespace App\Services;

use App\Models\WageOrder;
use App\Models\WageOrderRate;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Minimum wage lookups from published wage orders (drafts and cancelled
 * orders are ignored), and daily-rate helpers for comparing salaries.
 */
class WageRateService
{
    /**
     * Working days per year used to turn a monthly salary into a daily
     * equivalent (261 = Monday to Friday). Change to 313 for Monday to Saturday.
     */
    public const WORKING_DAYS_PER_YEAR = 261;

    /** @var Collection<int, WageOrderRate>|null */
    private ?Collection $published = null;

    public function refresh(): void
    {
        $this->published = null;
    }

    /**
     * Rates of published wage orders, oldest effective date first.
     *
     * @return Collection<int, WageOrderRate>
     */
    public function publishedRates(): Collection
    {
        return $this->published ??= WageOrderRate::query()
            ->with('wageOrder:id,region_code,order_no,title,status,effective_date')
            ->whereHas('wageOrder', fn ($q) => $q->where('status', 'published'))
            ->get()
            ->sortBy(fn (WageOrderRate $r) => $r->wageOrder->effective_date?->format('Y-m-d').'-'.str_pad((string) $r->wage_order_id, 8, '0', STR_PAD_LEFT))
            ->values();
    }

    /** @return Collection<int, WageOrderRate> */
    public function rates(string $region, string $classification): Collection
    {
        return $this->publishedRates()
            ->filter(fn (WageOrderRate $r) => $r->wageOrder->region_code === $region && $r->classification === $classification)
            ->values();
    }

    /** The rate in force on a date. */
    public function currentRate(string $region, string $classification, ?Carbon $date = null): ?WageOrderRate
    {
        $date ??= today();

        return $this->rates($region, $classification)
            ->filter(fn (WageOrderRate $r) => $r->wageOrder->effective_date->lte($date))
            ->last();
    }

    /** The next rate that takes effect after the date. */
    public function nextRate(string $region, string $classification, ?Carbon $date = null): ?WageOrderRate
    {
        $date ??= today();

        return $this->rates($region, $classification)
            ->first(fn (WageOrderRate $r) => $r->wageOrder->effective_date->gt($date));
    }

    /**
     * draft / cancelled as stored; for published orders:
     *   scheduled   effective date not reached
     *   active      at least one of its rates is the one in force
     *   superseded  a newer wage order replaced all its rates
     */
    public function displayStatus(WageOrder $order, ?Carbon $date = null): string
    {
        if ($order->status !== 'published') {
            return $order->status;
        }

        $date ??= today();

        if (! $order->effective_date || $order->effective_date->gt($date)) {
            return 'scheduled';
        }

        $rates = $order->relationLoaded('rates') ? $order->rates : $order->rates()->get();

        $inForce = $rates->contains(function (WageOrderRate $rate) use ($order, $date) {
            return $this->currentRate($order->region_code, $rate->classification, $date)?->id === $rate->id;
        });

        return $inForce ? 'active' : 'superseded';
    }

    // =====================================================
    // SALARY HELPERS
    // =====================================================

    /**
     * Daily equivalent of a salary, by salary type name (Daily, Monthly, Hourly).
     */
    public static function dailyEquivalent(float $basic, ?string $salaryType): float
    {
        $type = mb_strtolower((string) $salaryType);

        return match (true) {
            str_contains($type, 'daily') => $basic,
            str_contains($type, 'hour') => $basic * 8,
            str_contains($type, 'week') => $basic * 52 / self::WORKING_DAYS_PER_YEAR,
            default => $basic * 12 / self::WORKING_DAYS_PER_YEAR, // monthly
        };
    }

    /**
     * A daily amount expressed in the employee's salary type.
     */
    public static function fromDaily(float $daily, ?string $salaryType): float
    {
        $type = mb_strtolower((string) $salaryType);

        $value = match (true) {
            str_contains($type, 'daily') => $daily,
            str_contains($type, 'hour') => $daily / 8,
            str_contains($type, 'week') => $daily * self::WORKING_DAYS_PER_YEAR / 52,
            default => $daily * self::WORKING_DAYS_PER_YEAR / 12,
        };

        return ceil($value * 100) / 100; // round up so the result never falls below the minimum
    }

    public static function regionName(?string $code): ?string
    {
        return $code ? (WageOrder::REGIONS[$code] ?? $code) : null;
    }

    public static function classificationName(?string $key): ?string
    {
        return $key ? (WageOrder::CLASSIFICATIONS[$key] ?? $key) : null;
    }

    /**
     * Region and classification per location id.
     *
     * @return Collection<int, object>
     */
    public static function locations(): Collection
    {
        return DB::table('locations')->get(['id', 'location_name', 'region_code', 'wage_classification'])->keyBy('id');
    }
}