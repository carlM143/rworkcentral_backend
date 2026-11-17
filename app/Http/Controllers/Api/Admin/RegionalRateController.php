<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\WageOrder;
use App\Models\WageOrderRate;
use App\Services\WageRateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Current minimum wage per region and classification, and which region
 * each work location follows.
 *
 *   GET   /admin/regional-rates
 *   PATCH /admin/regional-rates/locations/{location}
 */
class RegionalRateController extends Controller
{
    public function __construct(private readonly WageRateService $rates)
    {
    }

    public function index(): JsonResponse
    {
        $today = today();

        // Every region/classification pair that has published rates.
        $pairs = $this->rates->publishedRates()
            ->map(fn (WageOrderRate $r) => [$r->wageOrder->region_code, $r->classification])
            ->unique(fn ($p) => $p[0].'|'.$p[1])
            ->values();

        $rates = $pairs->map(function (array $pair) use ($today) {
            [$region, $classification] = $pair;
            $current = $this->rates->currentRate($region, $classification, $today);
            $next = $this->rates->nextRate($region, $classification, $today);

            $history = $this->rates->rates($region, $classification)
                ->filter(fn ($r) => $r->wageOrder->effective_date->lte($today))
                ->reverse()
                ->take(6)
                ->map(fn ($r) => $this->rateRow($r))
                ->values();

            return [
                'region_code' => $region,
                'region_name' => WageRateService::regionName($region),
                'classification' => $classification,
                'classification_name' => WageRateService::classificationName($classification),
                'current' => $current ? $this->rateRow($current) : null,
                'next' => $next ? $this->rateRow($next) : null,
                'history' => $history,
            ];
        })
            ->sortBy([['region_code', 'asc'], ['classification', 'asc']])
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Regional rates retrieved.',
            'data' => [
                'as_of' => $today->toDateString(),
                'rates' => $rates,
                'locations' => $this->locations(),
            ],
        ]);
    }

    /**
     * Set the region and wage classification of a work location.
     */
    public function updateLocation(Request $request, int $location): JsonResponse
    {
        $validated = $request->validate([
            'region_code' => ['required', Rule::in(array_keys(WageOrder::REGIONS))],
            'wage_classification' => ['required', Rule::in(array_keys(WageOrder::CLASSIFICATIONS))],
        ]);

        $updated = DB::table('locations')->where('id', $location)->update([
            'region_code' => $validated['region_code'],
            'wage_classification' => $validated['wage_classification'],
            'updated_at' => now(),
        ]);

        abort_unless($updated || DB::table('locations')->where('id', $location)->exists(), 404, 'Location not found.');

        return response()->json([
            'success' => true,
            'message' => 'Location updated.',
            'data' => collect($this->locations())->firstWhere('id', $location),
        ]);
    }

    // =====================================================
    // HELPERS
    // =====================================================

    private function locations(): array
    {
        // Active employees per location, from each employee's latest employment.
        $counts = DB::table('employments')
            ->join('employees', 'employees.id', '=', 'employments.employee_id')
            ->where('employees.status', true)
            ->whereRaw('employments.id = (SELECT MAX(e2.id) FROM employments e2 WHERE e2.employee_id = employments.employee_id)')
            ->groupBy('employments.location_id')
            ->selectRaw('employments.location_id, COUNT(*) AS total')
            ->pluck('total', 'location_id');

        return DB::table('locations')
            ->orderBy('location_name')
            ->get(['id', 'location_name', 'city', 'province', 'status', 'region_code', 'wage_classification'])
            ->map(function ($l) use ($counts) {
                $current = $l->region_code
                    ? $this->rates->currentRate($l->region_code, $l->wage_classification ?? 'non_agriculture')
                    : null;

                return [
                    'id' => $l->id,
                    'name' => $l->location_name,
                    'city' => $l->city,
                    'province' => $l->province,
                    'active' => (bool) $l->status,
                    'region_code' => $l->region_code,
                    'region_name' => WageRateService::regionName($l->region_code),
                    'wage_classification' => $l->wage_classification,
                    'classification_name' => WageRateService::classificationName($l->wage_classification),
                    'employees' => (int) ($counts[$l->id] ?? 0),
                    'minimum_daily' => $current ? (float) $current->daily_rate : null,
                    'minimum_order_no' => $current?->wageOrder?->order_no,
                ];
            })
            ->values()
            ->all();
    }

    private function rateRow(WageOrderRate $rate): array
    {
        return [
            'wage_order_id' => $rate->wage_order_id,
            'order_no' => $rate->wageOrder?->order_no,
            'effective_date' => $rate->wageOrder?->effective_date?->toDateString(),
            'daily_rate' => (float) $rate->daily_rate,
            'cola' => (float) $rate->cola,
            'total' => round((float) $rate->daily_rate + (float) $rate->cola, 2),
            'previous_rate' => $rate->previous_rate !== null ? (float) $rate->previous_rate : null,
        ];
    }
}