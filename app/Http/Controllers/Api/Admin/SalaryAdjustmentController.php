<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SalaryAdjustment;
use App\Models\SalarySetup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        return response()->json([
            'success' => true,
            'message' => 'Salary adjustments retrieved successfully.',
            'data' => $adjustments
                ->map(fn (SalaryAdjustment $item) => $this->formatAdjustment($item))
                ->values(),
        ]);
    }

    // ==========================================
    // CREATE SALARY ADJUSTMENT
    // ==========================================

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => [
                'required', 'integer', 'exists:employees,id',
            ],

            'salary_setup_id' => [
                'required', 'integer', 'exists:salary_setups,id',
            ],

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

            'effective_date' => [
                'required', 'date_format:Y-m-d',
            ],

            'reason' => [
                'nullable', 'string', 'max:5000',
            ],
        ]);

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

            $oldAllowance = $salarySetup->allowance;

            $newAllowance = array_key_exists('new_allowance', $validated)
                ? $validated['new_allowance']
                : $oldAllowance;

            $oldBasic = $this->toCents($salarySetup->basic_salary);
            $newBasic = $this->toCents($validated['new_basic_salary']);

            $oldTotal = $oldBasic + $this->toCents($oldAllowance);
            $newTotal = $newBasic + $this->toCents($newAllowance);

            $sameAllowance =
                $this->normalizeMoney($oldAllowance) ===
                $this->normalizeMoney($newAllowance);

            if ($oldBasic === $newBasic && $sameAllowance) {
                throw ValidationException::withMessages([
                    'new_basic_salary' =>
                        'The new salary and allowance must differ from the current values.',
                ]);
            }

            if (
                $validated['adjustment_type'] === 'increase' &&
                $newTotal <= $oldTotal
            ) {
                throw ValidationException::withMessages([
                    'adjustment_type' =>
                        'An increase must raise the combined basic salary and allowance.',
                ]);
            }

            if (
                $validated['adjustment_type'] === 'decrease' &&
                $newTotal >= $oldTotal
            ) {
                throw ValidationException::withMessages([
                    'adjustment_type' =>
                        'A decrease must lower the combined basic salary and allowance.',
                ]);
            }

            // Previous values always come from the database.
            return SalaryAdjustment::create([
                'employee_id' => $salarySetup->employee_id,
                'salary_setup_id' => $salarySetup->id,

                'adjustment_type' => $validated['adjustment_type'],

                'previous_basic_salary' => $salarySetup->basic_salary,
                'new_basic_salary' => $validated['new_basic_salary'],

                'previous_allowance' => $oldAllowance,
                'new_allowance' => $newAllowance,

                'effective_date' => $validated['effective_date'],
                'reason' => $validated['reason'] ?? null,

                'status' => 'pending',
            ]);
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
        SalaryAdjustment $item
    ): array {
        $employee = $item->employee;

        $employeeName = $employee
            ? trim(implode(' ', array_filter([
                $employee->firstname,
                $employee->middlename,
                $employee->lastname,
            ])))
            : 'Unknown Employee';

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
}
