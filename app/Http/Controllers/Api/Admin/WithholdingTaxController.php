<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\WithholdingTaxService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WithholdingTaxController extends Controller
{
    private const FREQUENCIES = ['daily', 'weekly', 'semi_monthly', 'monthly'];

    public function __construct(private readonly WithholdingTaxService $tax)
    {
    }

    public function index(): JsonResponse
    {
        $today = today()->toDateString();

        $tables = DB::table('withholding_tax_tables')
            ->orderBy('frequency')
            ->orderByDesc('effective_from')
            ->get();

        $brackets = DB::table('withholding_tax_brackets')
            ->whereIn('withholding_tax_table_id', $tables->pluck('id'))
            ->orderBy('over')
            ->get()
            ->groupBy('withholding_tax_table_id');

        $data = $tables->map(fn ($t) => [
            'id' => (int) $t->id,
            'name' => $t->name,
            'frequency' => $t->frequency,
            'effective_from' => $t->effective_from,
            'effective_to' => $t->effective_to,
            'is_active' => (bool) $t->is_active,
            'reference' => $t->reference,
            'notes' => $t->notes,
            'in_force' => $t->is_active && $t->effective_from <= $today && (! $t->effective_to || $t->effective_to >= $today),
            'brackets' => $brackets->get($t->id, collect())->map(fn ($b) => [
                'over' => (float) $b->over,
                'up_to' => $b->up_to === null ? null : (float) $b->up_to,
                'base_tax' => (float) $b->base_tax,
                'rate' => (float) $b->rate,
            ])->values(),
        ])->values();

        $settings = $this->tax->settings();
        $row = DB::table('payroll_tax_settings')->first();

        return response()->json([
            'success' => true,
            'data' => $data,
            'settings' => [
                'apply_mwe_exemption' => $settings->apply_mwe_exemption,
                'main_allowance_taxable' => $settings->main_allowance_taxable,
                'non_taxable_allowances' => json_decode($row->non_taxable_allowances ?? '[]', true) ?: [],
            ],
            'allowance_names' => $this->tax->allowanceNames()->values(),
            'frequencies' => self::FREQUENCIES,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateTable($request);

        $id = DB::transaction(function () use ($validated) {
            // The table it replaces ends the day before.
            DB::table('withholding_tax_tables')
                ->where('frequency', $validated['frequency'])
                ->where('effective_from', '<', $validated['effective_from'])
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $validated['effective_from']))
                ->update(['effective_to' => Carbon::parse($validated['effective_from'])->subDay()->toDateString(), 'updated_at' => now()]);

            $id = DB::table('withholding_tax_tables')->insertGetId([
                'name' => $validated['name'],
                'frequency' => $validated['frequency'],
                'effective_from' => $validated['effective_from'],
                'effective_to' => $validated['effective_to'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
                'reference' => $validated['reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->saveBrackets($id, $validated['brackets']);

            return $id;
        });

        return response()->json(['success' => true, 'message' => 'Withholding tax table added.', 'id' => $id], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        abort_unless(DB::table('withholding_tax_tables')->where('id', $id)->exists(), 404, 'Table not found.');
        $validated = $this->validateTable($request);

        DB::transaction(function () use ($id, $validated) {
            DB::table('withholding_tax_tables')->where('id', $id)->update([
                'name' => $validated['name'],
                'frequency' => $validated['frequency'],
                'effective_from' => $validated['effective_from'],
                'effective_to' => $validated['effective_to'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
                'reference' => $validated['reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'updated_at' => now(),
            ]);

            DB::table('withholding_tax_brackets')->where('withholding_tax_table_id', $id)->delete();
            $this->saveBrackets($id, $validated['brackets']);
        });

        return response()->json(['success' => true, 'message' => 'Withholding tax table saved.']);
    }

    public function destroy(int $id): JsonResponse
    {
        $table = DB::table('withholding_tax_tables')->where('id', $id)->first();
        abort_unless($table, 404, 'Table not found.');

        $others = DB::table('withholding_tax_tables')
            ->where('frequency', $table->frequency)
            ->where('id', '!=', $id)
            ->count();

        if ($others === 0) {
            throw ValidationException::withMessages([
                'table' => 'This is the only table for this pay frequency. Add a new one before deleting it.',
            ]);
        }

        DB::table('withholding_tax_tables')->where('id', $id)->delete();

        return response()->json(['success' => true, 'message' => 'Withholding tax table deleted.']);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'apply_mwe_exemption' => ['required', 'boolean'],
            'main_allowance_taxable' => ['required', 'boolean'],
            'non_taxable_allowances' => ['nullable', 'array'],
            'non_taxable_allowances.*' => ['string', 'max:100'],
        ]);

        $values = [
            'apply_mwe_exemption' => $validated['apply_mwe_exemption'],
            'main_allowance_taxable' => $validated['main_allowance_taxable'],
            'non_taxable_allowances' => json_encode(array_values(array_unique($validated['non_taxable_allowances'] ?? []))),
            'updated_at' => now(),
        ];

        if (DB::table('payroll_tax_settings')->exists()) {
            DB::table('payroll_tax_settings')->update($values);
        } else {
            DB::table('payroll_tax_settings')->insert($values + ['created_at' => now()]);
        }

        return response()->json(['success' => true, 'message' => 'Withholding tax settings saved. Regenerate draft payroll to apply them.']);
    }

    public function calculate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'frequency' => ['required', Rule::in([...self::FREQUENCIES, 'bi_weekly'])],
            'taxable' => ['required', 'numeric', 'min:0'],
            'date' => ['nullable', 'date'],
        ]);

        [$tax, $bracket, $table] = $this->tax->taxForFrequency(
            $validated['frequency'],
            $validated['date'] ?? today(),
            (float) $validated['taxable'],
        );

        return response()->json([
            'success' => true,
            'data' => [
                'taxable' => round((float) $validated['taxable'], 2),
                'tax' => $tax,
                'bracket' => $bracket,
                'table' => $table ? ['id' => $table['id'], 'name' => $table['name']] : null,
            ],
        ]);
    }

    private function validateTable(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'frequency' => ['required', Rule::in(self::FREQUENCIES)],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active' => ['nullable', 'boolean'],
            'reference' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'brackets' => ['required', 'array', 'min:1', 'max:20'],
            'brackets.*.over' => ['required', 'numeric', 'min:0'],
            'brackets.*.up_to' => ['nullable', 'numeric', 'min:0'],
            'brackets.*.base_tax' => ['required', 'numeric', 'min:0'],
            'brackets.*.rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $brackets = collect($validated['brackets'])->sortBy('over')->values();
        if ((float) $brackets->first()['over'] !== 0.0) {
            throw ValidationException::withMessages(['brackets' => 'The first bracket must start at 0.']);
        }

        return $validated;
    }

    private function saveBrackets(int $tableId, array $brackets): void
    {
        collect($brackets)->sortBy('over')->values()->each(function ($b, $i) use ($tableId) {
            DB::table('withholding_tax_brackets')->insert([
                'withholding_tax_table_id' => $tableId,
                'sort' => $i + 1,
                'over' => $b['over'],
                'up_to' => $b['up_to'] ?? null,
                'base_tax' => $b['base_tax'],
                'rate' => $b['rate'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }
}
