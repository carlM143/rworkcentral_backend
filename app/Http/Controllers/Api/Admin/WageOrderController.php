<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\WageOrder;
use App\Models\WageOrderRate;
use App\Services\WageRateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Regional minimum wage orders. One effective date per order and one
 * new minimum per classification (the full increase at once).
 *
 *   GET    /admin/wage-orders/meta
 *   GET    /admin/wage-orders?search=&region=&status=
 *   POST   /admin/wage-orders
 *   GET    /admin/wage-orders/{wageOrder}
 *   PUT    /admin/wage-orders/{wageOrder}
 *   DELETE /admin/wage-orders/{wageOrder}            draft only
 *   PATCH  /admin/wage-orders/{wageOrder}/publish
 *   PATCH  /admin/wage-orders/{wageOrder}/cancel
 *   POST   /admin/wage-orders/{wageOrder}/document
 *   DELETE /admin/wage-orders/{wageOrder}/document
 */
class WageOrderController extends Controller
{
    private const DEFAULT_REGION = 'XI';

    public function __construct(private readonly WageRateService $rates)
    {
    }

    public function meta(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Wage order options retrieved.',
            'data' => [
                'default_region' => self::DEFAULT_REGION,
                'regions' => collect(WageOrder::REGIONS)->map(fn ($name, $code) => ['code' => $code, 'name' => $name])->values(),
                'classifications' => collect(WageOrder::CLASSIFICATIONS)->map(fn ($name, $key) => ['key' => $key, 'name' => $name])->values(),
                'working_days_per_year' => WageRateService::WORKING_DAYS_PER_YEAR,
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'region' => ['nullable', Rule::in(array_keys(WageOrder::REGIONS))],
            'status' => ['nullable', 'in:draft,scheduled,active,superseded,cancelled'],
        ]);

        $search = mb_strtolower(trim((string) ($validated['search'] ?? '')));

        $all = WageOrder::query()
            ->with('rates')
            ->withCount('salaryAdjustments')
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (WageOrder $o) => $this->summary($o));

        $orders = $all
            ->when($validated['region'] ?? null, fn ($c, $region) => $c->where('region_code', $region))
            ->when($search !== '', fn ($c) => $c->filter(fn ($o) => str_contains(
                mb_strtolower($o['order_no'].' '.$o['title'].' '.$o['region_name'].' '.implode(' ', $o['classification_names'])),
                $search
            )))
            ->when($validated['status'] ?? null, fn ($c, $status) => $c->where('display_status', $status))
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Wage orders retrieved.',
            'data' => $orders,
            'stats' => [
                'total' => $all->count(),
                'active' => $all->where('display_status', 'active')->count(),
                'scheduled' => $all->where('display_status', 'scheduled')->count(),
                'regions' => $all->whereIn('display_status', ['active', 'scheduled'])->pluck('region_code')->unique()->count(),
            ],
        ]);
    }

    public function show(WageOrder $wageOrder): JsonResponse
    {
        return $this->respond($wageOrder, 'Wage order retrieved.');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateOrder($request);

        $order = DB::transaction(function () use ($validated, $request) {
            $order = WageOrder::create($this->orderAttributes($validated) + [
                'status' => 'draft',
                'created_by' => $request->user()?->id,
            ]);

            $this->saveRates($order, $validated['rates']);

            return $order;
        });

        return $this->respond($order, "Wage order {$order->order_no} saved as draft.", 201);
    }

    public function update(Request $request, WageOrder $wageOrder): JsonResponse
    {
        if ($wageOrder->status === 'cancelled') {
            abort(409, 'Cancelled wage orders cannot be edited.');
        }

        $validated = $this->validateOrder($request, $wageOrder);

        DB::transaction(function () use ($wageOrder, $validated) {
            $wageOrder->update($this->orderAttributes($validated));
            $wageOrder->rates()->delete();
            $this->saveRates($wageOrder, $validated['rates']);
        });

        return $this->respond($wageOrder, "Wage order {$wageOrder->order_no} updated.");
    }

    public function destroy(WageOrder $wageOrder): JsonResponse
    {
        if ($wageOrder->status !== 'draft') {
            abort(409, 'Only draft wage orders can be deleted. Cancel a published order instead.');
        }

        if ($wageOrder->document_path) {
            Storage::disk('public')->delete($wageOrder->document_path);
        }

        $wageOrder->delete();

        return response()->json(['success' => true, 'message' => 'Wage order deleted.', 'data' => null]);
    }

    public function publish(WageOrder $wageOrder): JsonResponse
    {
        if ($wageOrder->status !== 'draft') {
            abort(409, 'Only draft wage orders can be published.');
        }

        if (! $wageOrder->rates()->exists()) {
            throw ValidationException::withMessages(['rates' => 'Add at least one rate before publishing.']);
        }

        $wageOrder->update([
            'status' => 'published',
            'published_date' => $wageOrder->published_date ?? today(),
        ]);

        return $this->respond($wageOrder, "Wage order {$wageOrder->order_no} published. You can now apply it to employees.");
    }

    public function cancel(WageOrder $wageOrder): JsonResponse
    {
        if ($wageOrder->status === 'cancelled') {
            abort(409, 'This wage order is already cancelled.');
        }

        $wageOrder->update(['status' => 'cancelled']);

        return $this->respond($wageOrder, "Wage order {$wageOrder->order_no} cancelled. Salary adjustments already created from it are not changed.");
    }

    public function uploadDocument(Request $request, WageOrder $wageOrder): JsonResponse
    {
        $request->validate([
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $file = $request->file('document');
        $path = $file->store("wage-orders/{$wageOrder->id}", 'public');
        $old = $wageOrder->document_path;

        $wageOrder->update(['document_path' => $path, 'document_name' => $file->getClientOriginalName()]);

        if ($old && $old !== $path) {
            Storage::disk('public')->delete($old);
        }

        return $this->respond($wageOrder, 'Document uploaded.');
    }

    public function deleteDocument(WageOrder $wageOrder): JsonResponse
    {
        if ($wageOrder->document_path) {
            Storage::disk('public')->delete($wageOrder->document_path);
        }

        $wageOrder->update(['document_path' => null, 'document_name' => null]);

        return $this->respond($wageOrder, 'Document removed.');
    }

    // =====================================================
    // HELPERS
    // =====================================================

    private function validateOrder(Request $request, ?WageOrder $order = null): array
    {
        return $request->validate([
            'region_code' => ['required', Rule::in(array_keys(WageOrder::REGIONS))],
            'order_no' => ['required', 'string', 'max:50', Rule::unique('wage_orders', 'order_no')->ignore($order?->id)],
            'title' => ['required', 'string', 'max:255'],
            'effective_date' => ['required', 'date'],
            'issued_date' => ['nullable', 'date'],
            'published_date' => ['nullable', 'date'],
            'source' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:5000'],
            'rates' => ['required', 'array', 'min:1', 'max:10'],
            'rates.*.classification' => ['required', 'distinct', Rule::in(array_keys(WageOrder::CLASSIFICATIONS))],
            'rates.*.daily_rate' => ['required', 'numeric', 'min:1', 'max:99999'],
            'rates.*.cola' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'rates.*.previous_rate' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'rates.*.notes' => ['nullable', 'string', 'max:255'],
        ], [
            'order_no.unique' => 'This wage order number already exists.',
            'rates.required' => 'Add at least one rate.',
            'rates.*.classification.distinct' => 'Each classification can only be listed once.',
        ]);
    }

    private function orderAttributes(array $validated): array
    {
        return [
            'region_code' => $validated['region_code'],
            'order_no' => strtoupper(trim($validated['order_no'])),
            'title' => trim($validated['title']),
            'effective_date' => $validated['effective_date'],
            'issued_date' => $validated['issued_date'] ?? null,
            'published_date' => $validated['published_date'] ?? null,
            'source' => isset($validated['source']) ? trim($validated['source']) ?: null : null,
            'remarks' => $validated['remarks'] ?? null,
        ];
    }

    /**
     * "Previous" defaults to the minimum in force the day before this order.
     */
    private function saveRates(WageOrder $order, array $rows): void
    {
        $dayBefore = $order->effective_date->copy()->subDay();

        foreach ($rows as $row) {
            $previous = $row['previous_rate'] ?? null;

            if ($previous === null || $previous === '') {
                $previous = $this->rates
                    ->currentRate($order->region_code, $row['classification'], $dayBefore)
                    ?->daily_rate;
            }

            $order->rates()->create([
                'classification' => $row['classification'],
                'daily_rate' => $row['daily_rate'],
                'cola' => $row['cola'] ?? 0,
                'previous_rate' => $previous,
                'notes' => $row['notes'] ?? null,
            ]);
        }
    }

    private function summary(WageOrder $order): array
    {
        $rates = $order->rates;
        $headline = $rates->firstWhere('classification', 'non_agriculture') ?? $rates->first();

        return [
            'id' => $order->id,
            'region_code' => $order->region_code,
            'region_name' => WageRateService::regionName($order->region_code),
            'order_no' => $order->order_no,
            'title' => $order->title,
            'effective_date' => $order->effective_date?->toDateString(),
            'status' => $order->status,
            'display_status' => $this->rates->displayStatus($order),
            'classification_names' => $rates->map(fn ($r) => WageRateService::classificationName($r->classification))->values()->all(),
            'headline' => $headline ? [
                'classification' => WageRateService::classificationName($headline->classification),
                'previous_rate' => $headline->previous_rate !== null ? (float) $headline->previous_rate : null,
                'new_rate' => (float) $headline->daily_rate,
                'increase' => $headline->previous_rate !== null
                    ? round((float) $headline->daily_rate - (float) $headline->previous_rate, 2)
                    : null,
            ] : null,
            'adjustments_created' => (int) ($order->salary_adjustments_count ?? $order->salaryAdjustments()->count()),
            'has_document' => (bool) $order->document_path,
            'source' => $order->source,
        ];
    }

    private function respond(WageOrder $order, string $message, int $status = 200): JsonResponse
    {
        $this->rates->refresh();
        $order->load(['rates', 'creator:id,name'])->loadCount('salaryAdjustments');
        $today = today();

        $rates = $order->rates->map(function (WageOrderRate $rate) use ($order, $today) {
            $inForce = $order->status === 'published'
                && $this->rates->currentRate($order->region_code, $rate->classification, $today)?->id === $rate->id;

            return [
                'id' => $rate->id,
                'classification' => $rate->classification,
                'classification_name' => WageRateService::classificationName($rate->classification),
                'daily_rate' => (float) $rate->daily_rate,
                'cola' => (float) $rate->cola,
                'minimum' => round((float) $rate->daily_rate + (float) $rate->cola, 2),
                'previous_rate' => $rate->previous_rate !== null ? (float) $rate->previous_rate : null,
                'increase' => $rate->previous_rate !== null ? round((float) $rate->daily_rate - (float) $rate->previous_rate, 2) : null,
                'notes' => $rate->notes,
                'in_force' => $inForce,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $this->summary($order) + [
                'issued_date' => $order->issued_date?->toDateString(),
                'published_date' => $order->published_date?->toDateString(),
                'remarks' => $order->remarks,
                'document_url' => $order->document_path ? Storage::disk('public')->url($order->document_path) : null,
                'document_name' => $order->document_name,
                'created_by' => $order->creator?->name,
                'created_at' => $order->created_at?->toIso8601String(),
                'rates' => $rates,
            ],
        ], $status);
    }
}