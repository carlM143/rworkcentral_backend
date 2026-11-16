<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SalaryAdjustment;
use App\Models\SalaryAdjustmentAllowance;
use App\Models\SalarySetup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SalaryAdjustmentController extends Controller
{
    private const RELATIONS = [
        'employee:id,emp_id,firstname,middlename,lastname',
        'salarySetup:id,employee_id,salary_type_id',
        'salarySetup.salaryType:id,type_name',
        'approvedBy:id,name',
        'appliedBy:id,name',
    ];

    // ==========================================
    // GET ALL SALARY ADJUSTMENTS
    // ==========================================

    public function index(): JsonResponse
    {
        $adjustments = SalaryAdjustment::query()
            ->with(self::RELATIONS)
            ->latest('id')
            ->get();

        // One query for all allowances, grouped by adjustment id.
        $allowanceMap = SalaryAdjustmentAllowance::query()
            ->whereIn('salary_adjustment_id', $adjustments->pluck('id'))
            ->orderBy('id')
            ->get()
            ->groupBy('salary_adjustment_id');

        return response()->json([
            'success' => true,
            'message' => 'Salary adjustments retrieved successfully.',
            'data' => $adjustments
                ->map(fn (SalaryAdjustment $item) => $this->formatAdjustment(
                    $item,
                    $allowanceMap->get($item->id, collect())
                ))
                ->values(),
        ]);
    }

    // ==========================================
    // CREATE SALARY ADJUSTMENT
    // ==========================================

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(
            $this->rules(true),
            $this->messages()
        );

        $adjustment = DB::transaction(function () use ($validated) {
            $salarySetup = SalarySetup::query()
                ->whereKey($validated['salary_setup_id'])
                ->lockForUpdate()
                ->firstOrFail();

            // Ensure the salary setup belongs to the employee.
            if (
                (int) $salarySetup->employee_id !==
                (int) $validated['employee_id']
            ) {
                throw ValidationException::withMessages([
                    'salary_setup_id' =>
                        'The selected salary setup does not belong to this employee.',
                ]);
            }

            // Avoid conflicting outstanding adjustments.
            $hasOpenAdjustment = SalaryAdjustment::query()
                ->where('salary_setup_id', $salarySetup->id)
                ->whereIn('status', ['pending', 'approved'])
                ->exists();

            if ($hasOpenAdjustment) {
                throw ValidationException::withMessages([
                    'salary_setup_id' =>
                        'This employee already has a pending or approved salary adjustment.',
                ]);
            }

            [$attributes, $newExtras] = $this->prepare($validated, $salarySetup);

            $adjustment = SalaryAdjustment::create($attributes + [
                'employee_id' => $salarySetup->employee_id,
                'salary_setup_id' => $salarySetup->id,
                'status' => 'pending',
            ]);

            $this->saveExtras($adjustment, $newExtras);

            return $adjustment;
        });

        return $this->respond(
            $adjustment,
            'Salary adjustment created successfully.',
            201
        );
    }

    // ==========================================
    // VIEW SALARY ADJUSTMENT
    // ==========================================

    public function show(
        SalaryAdjustment $salaryAdjustment
    ): JsonResponse {
        return $this->respond(
            $salaryAdjustment,
            'Salary adjustment retrieved successfully.'
        );
    }

    // ==========================================
    // EDIT PENDING SALARY ADJUSTMENT
    // ==========================================

    public function update(
        Request $request,
        SalaryAdjustment $salaryAdjustment
    ): JsonResponse {
        $validated = $request->validate(
            $this->rules(false),
            $this->messages()
        );

        $adjustment = DB::transaction(function () use (
            $validated,
            $salaryAdjustment
        ) {
            $item = SalaryAdjustment::query()
                ->lockForUpdate()
                ->findOrFail($salaryAdjustment->id);

            if ($item->status !== 'pending') {
                abort(409, 'Only pending adjustments can be edited.');
            }

            $salarySetup = SalarySetup::query()
                ->whereKey($item->salary_setup_id)
                ->lockForUpdate()
                ->firstOrFail();

            // Previous values are refreshed from the current salary setup.
            [$attributes, $newExtras] = $this->prepare($validated, $salarySetup);

            $item->update($attributes);

            if (array_key_exists('allowances', $validated)) {
                SalaryAdjustmentAllowance::query()
                    ->where('salary_adjustment_id', $item->id)
                    ->delete();

                $this->saveExtras($item, $newExtras);
            }

            return $item;
        });

        return $this->respond(
            $adjustment,
            'Salary adjustment updated successfully.'
        );
    }

    // ==========================================
    // APPROVE SALARY ADJUSTMENT
    // ==========================================

    public function approve(
        Request $request,
        SalaryAdjustment $salaryAdjustment
    ): JsonResponse {
        $adjustment = DB::transaction(function () use (
            $request,
            $salaryAdjustment
        ) {
            $item = SalaryAdjustment::query()
                ->lockForUpdate()
                ->findOrFail($salaryAdjustment->id);

            if ($item->status !== 'pending') {
                abort(409, 'Only pending adjustments can be approved.');
            }

            $item->update([
                'status' => 'approved',
                'approved_by' => $request->user()->id,
                'approved_at' => today(),
            ]);

            return $item;
        });

        return $this->respond(
            $adjustment,
            'Salary adjustment approved successfully.'
        );
    }

    // ==========================================
    // REJECT SALARY ADJUSTMENT
    // ==========================================

    public function reject(
        SalaryAdjustment $salaryAdjustment
    ): JsonResponse {
        $adjustment = DB::transaction(function () use (
            $salaryAdjustment
        ) {
            $item = SalaryAdjustment::query()
                ->lockForUpdate()
                ->findOrFail($salaryAdjustment->id);

            if ($item->status !== 'pending') {
                abort(409, 'Only pending adjustments can be rejected.');
            }

            $item->update([
                'status' => 'rejected',
            ]);

            return $item;
        });

        return $this->respond(
            $adjustment,
            'Salary adjustment rejected successfully.'
        );
    }

    // ==========================================
    // APPLY APPROVED SALARY ADJUSTMENT
    // ==========================================

    public function apply(
        Request $request,
        SalaryAdjustment $salaryAdjustment
    ): JsonResponse {
        $adjustment = DB::transaction(function () use (
            $request,
            $salaryAdjustment
        ) {
            $item = SalaryAdjustment::query()
                ->lockForUpdate()
                ->findOrFail($salaryAdjustment->id);

            if ($item->status !== 'approved') {
                abort(409, 'Only approved adjustments can be applied.');
            }

            if (
                $item->effective_date->toDateString() >
                today()->toDateString()
            ) {
                abort(
                    422,
                    'The salary adjustment effective date has not yet arrived.'
                );
            }

            $salarySetup = SalarySetup::query()
                ->whereKey($item->salary_setup_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                (int) $salarySetup->employee_id !==
                (int) $item->employee_id
            ) {
                abort(409, 'The employee salary setup does not match.');
            }

            // Prevent applying an outdated adjustment.
            $basicMatches =
                $this->normalizeMoney($salarySetup->basic_salary) ===
                $this->normalizeMoney($item->previous_basic_salary);

            $allowanceMatches =
                $this->normalizeMoney($salarySetup->allowance) ===
                $this->normalizeMoney($item->previous_allowance);

            if (!$basicMatches || !$allowanceMatches) {
                abort(
                    409,
                    'The current salary has changed. Review the adjustment before applying it.'
                );
            }

            // Actual employee salary update.
            $salarySetup->update([
                'basic_salary' => $item->new_basic_salary,
                'allowance' => $item->new_allowance,
            ]);

            // Copy the additional allowances to employee_allowance.
            // Only when this adjustment has rows; otherwise existing ones stay.
            $extras = SalaryAdjustmentAllowance::query()
                ->where('salary_adjustment_id', $item->id)
                ->orderBy('id')
                ->get();

            if ($extras->isNotEmpty()) {
                DB::table('employee_allowance')
                    ->where('salary_setup_id', $salarySetup->id)
                    ->delete();

                DB::table('employee_allowance')->insert(
                    $extras->map(fn (SalaryAdjustmentAllowance $row) => [
                        'salary_setup_id' => $salarySetup->id,
                        'allowance_name' => $row->allowance_name,
                        'allowance' => $row->amount,
                    ])->all()
                );
            }

            // Record who applied the adjustment.
            $item->update([
                'status' => 'applied',
                'applied_by' => $request->user()->id,
                'applied_at' => now(),
            ]);

            return $item;
        });

        return $this->respond(
            $adjustment,
            'Salary adjustment applied successfully.'
        );
    }

    // ==========================================
    // VALIDATION
    // ==========================================

    /**
     * @param  bool  $creating  employee and salary setup are only sent on create
     */
    private function rules(bool $creating): array
    {
        $rules = [
            'adjustment_type' => [
                'required',
                Rule::in(['increase', 'decrease', 'correction']),
            ],

            'new_basic_salary' => [
                'required', 'numeric',
                'between:0,9999999999.99',
                'decimal:0,2',
            ],

            'new_allowance' => [
                'sometimes', 'nullable', 'numeric',
                'between:0,9999999999.99',
                'decimal:0,2',
            ],

            // Multiple additional allowances
            'allowances' => ['sometimes', 'nullable', 'array', 'max:20'],

            'allowances.*.allowance_name' => [
                'required', 'string', 'max:100', 'distinct:ignore_case',
            ],

            'allowances.*.amount' => [
                'required', 'numeric',
                'between:0,9999999999.99',
                'decimal:0,2',
            ],

            'effective_date' => [
                'required', 'date_format:Y-m-d',
            ],

            'reason' => [
                'nullable', 'string', 'max:5000',
            ],
        ];

        if ($creating) {
            $rules['employee_id'] = ['required', 'integer', 'exists:employees,id'];
            $rules['salary_setup_id'] = ['required', 'integer', 'exists:salary_setups,id'];
        }

        return $rules;
    }

    private function messages(): array
    {
        return [
            'allowances.*.allowance_name.required' => 'Each additional allowance needs a name.',
            'allowances.*.allowance_name.distinct' => 'Allowance names must not repeat.',
            'allowances.*.amount.required' => 'Each additional allowance needs an amount.',
        ];
    }

    /**
     * Shared by create and edit: compares the request with the current
     * salary setup, checks the adjustment type and returns the attributes
     * to save plus the additional allowance rows.
     *
     * @return array{0: array<string, mixed>, 1: Collection<int, array{allowance_name: string, amount: mixed}>}
     */
    private function prepare(array $validated, SalarySetup $salarySetup): array
    {
        $oldAllowance = $salarySetup->allowance;

        $newAllowance = array_key_exists('new_allowance', $validated)
            ? $validated['new_allowance']
            : $oldAllowance;

        // ---- Additional allowances ----
        $newExtras = collect($validated['allowances'] ?? [])
            ->map(fn (array $row) => [
                'allowance_name' => trim($row['allowance_name']),
                'amount' => $row['amount'],
            ])
            ->values();

        $hasExtras = $newExtras->isNotEmpty();

        $oldExtras = DB::table('employee_allowance')
            ->where('salary_setup_id', $salarySetup->id)
            ->get(['allowance_name', 'allowance']);

        $oldExtrasCents = $oldExtras->sum(
            fn ($row) => $this->toCents($row->allowance)
        );

        // If no extras were submitted, existing extras stay untouched.
        $newExtrasCents = $hasExtras
            ? $newExtras->sum(fn ($row) => $this->toCents($row['amount']))
            : $oldExtrasCents;

        $extrasChanged = $hasExtras && (
            $this->extrasSignature($oldExtras->map(fn ($r) => [
                'allowance_name' => $r->allowance_name,
                'amount' => $r->allowance,
            ])) !== $this->extrasSignature($newExtras)
        );

        $oldBasic = $this->toCents($salarySetup->basic_salary);
        $newBasic = $this->toCents($validated['new_basic_salary']);

        $oldTotal = $oldBasic + $this->toCents($oldAllowance) + $oldExtrasCents;
        $newTotal = $newBasic + $this->toCents($newAllowance) + $newExtrasCents;

        $sameAllowance =
            $this->normalizeMoney($oldAllowance) ===
            $this->normalizeMoney($newAllowance);

        if ($oldBasic === $newBasic && $sameAllowance && !$extrasChanged) {
            throw ValidationException::withMessages([
                'new_basic_salary' =>
                    'The new salary and allowances must differ from the current values.',
            ]);
        }

        if (
            $validated['adjustment_type'] === 'increase' &&
            $newTotal <= $oldTotal
        ) {
            throw ValidationException::withMessages([
                'adjustment_type' =>
                    'An increase must raise the combined basic salary and allowances.',
            ]);
        }

        if (
            $validated['adjustment_type'] === 'decrease' &&
            $newTotal >= $oldTotal
        ) {
            throw ValidationException::withMessages([
                'adjustment_type' =>
                    'A decrease must lower the combined basic salary and allowances.',
            ]);
        }

        // Previous values always come from the database.
        $attributes = [
            'adjustment_type' => $validated['adjustment_type'],

            'previous_basic_salary' => $salarySetup->basic_salary,
            'new_basic_salary' => $validated['new_basic_salary'],

            'previous_allowance' => $oldAllowance,
            'new_allowance' => $newAllowance,

            'effective_date' => $validated['effective_date'],
            'reason' => $validated['reason'] ?? null,
        ];

        return [$attributes, $newExtras];
    }

    private function saveExtras(SalaryAdjustment $adjustment, Collection $extras): void
    {
        foreach ($extras as $row) {
            SalaryAdjustmentAllowance::create([
                'salary_adjustment_id' => $adjustment->id,
                'allowance_name' => $row['allowance_name'],
                'amount' => $row['amount'],
            ]);
        }
    }

    // ==========================================
    // API RESPONSE FORMAT
    // ==========================================

    private function respond(
        SalaryAdjustment $adjustment,
        string $message,
        int $statusCode = 200
    ): JsonResponse {
        $adjustment->load(self::RELATIONS);

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $this->formatAdjustment($adjustment),
        ], $statusCode);
    }

    private function formatAdjustment(
        SalaryAdjustment $item,
        ?Collection $allowances = null
    ): array {
        $employee = $item->employee;

        $employeeName = $employee
            ? trim(implode(' ', array_filter([
                $employee->firstname,
                $employee->middlename,
                $employee->lastname,
            ])))
            : 'Unknown Employee';

        $allowances ??= SalaryAdjustmentAllowance::query()
            ->where('salary_adjustment_id', $item->id)
            ->orderBy('id')
            ->get();

        $allowanceList = $allowances->map(fn (SalaryAdjustmentAllowance $row) => [
            'id' => $row->id,
            'allowance_name' => $row->allowance_name,
            'amount' => (string) $row->amount,
        ])->values();

        $allowancesTotal = $allowances->sum(
            fn (SalaryAdjustmentAllowance $row) => $this->toCents($row->amount)
        ) / 100;

        return [
            'id' => $item->id,

            'employee_id' => $item->employee_id,
            'employee_name' => $employeeName,
            'emp_id' => $employee?->emp_id,

            'salary_setup_id' => $item->salary_setup_id,
            'salary_type' => $item->salarySetup?->salaryType?->type_name,

            'adjustment_type' => $item->adjustment_type,

            'previous_basic_salary' => $item->previous_basic_salary,
            'new_basic_salary' => $item->new_basic_salary,

            'previous_allowance' => $item->previous_allowance,
            'new_allowance' => $item->new_allowance,

            'allowances' => $allowanceList,
            'allowances_total' => number_format($allowancesTotal, 2, '.', ''),

            'effective_date' => $item->effective_date?->format('Y-m-d'),
            'reason' => $item->reason,

            'status' => $item->status,

            'approved_by' => $item->approved_by,
            'approved_by_name' => $item->approvedBy?->name,
            'approved_at' => $item->approved_at?->format('Y-m-d'),

            'applied_by' => $item->applied_by,
            'applied_by_name' => $item->appliedBy?->name,
            'applied_at' => $item->applied_at?->toIso8601String(),

            'created_at' => $item->created_at?->toIso8601String(),
            'updated_at' => $item->updated_at?->toIso8601String(),
        ];
    }

    // ==========================================
    // MONETARY VALUE HELPERS
    // ==========================================

    private function normalizeMoney(
        string|int|float|null $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function toCents(
        string|int|float|null $value
    ): int {
        $normalized = $this->normalizeMoney($value);

        if ($normalized === null) {
            return 0;
        }

        [$whole, $fraction] = explode('.', $normalized);

        return ((int) $whole * 100) + (int) $fraction;
    }

    /**
     * Order-independent, case-insensitive fingerprint of an allowance list,
     * used to detect whether the extras actually changed.
     */
    private function extrasSignature(iterable $rows): string
    {
        $map = [];

        foreach ($rows as $row) {
            $map[mb_strtolower(trim($row['allowance_name']))] =
                $this->normalizeMoney($row['amount']);
        }

        ksort($map);

        return json_encode($map);
    }
}