<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContributionChangeLog;
use App\Models\ContributionRateSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ContributionRateScheduleController extends Controller
{
    /**
     * Relationships returned with every rate schedule.
     */
    private const RELATIONS = 'contributionType:id,contribution_code,contribution_name,provider,is_status';

    /**
     * Allowed deduction frequencies for fixed contributions.
     */
    public const FREQUENCIES = ['monthly', 'semi_monthly', 'weekly', 'per_payroll'];

    private function snapshot(ContributionRateSchedule $schedule): array
    {
        return [
            'contribution_type_id' => $schedule->contribution_type_id,
            'calculation_method' => $schedule->calculation_method,
            'salary_basis' => $schedule->salary_basis,
            'employee_rate' => $schedule->employee_rate,
            'employer_rate' => $schedule->employer_rate,
            'employee_amount' => $schedule->employee_amount,
            'employer_amount' => $schedule->employer_amount,
            'frequency' => $schedule->frequency,
            'minimum_salary_base' => $schedule->minimum_salary_base,
            'maximum_salary_base' => $schedule->maximum_salary_base,
            'effective_from' => $schedule->effective_from?->format('Y-m-d'),
            'effective_to' => $schedule->effective_to?->format('Y-m-d'),
            'is_status' => (bool) $schedule->is_status,
        ];
    }

    /**
     * Validation rules shared by store and update.
     *
     * - percentage: salary basis + employee/employer rate (%) + optional salary floor/ceiling
     * - fixed:      employee/employer amount (₱) + frequency
     * - bracket:    salary basis + optional salary floor/ceiling (rates live in the brackets table)
     */
    private function rules(): array
    {
        return [
            'contribution_type_id' => ['required', 'integer', 'exists:contribution_types,id'],
            'calculation_method' => ['required', Rule::in(['percentage', 'bracket', 'fixed'])],
            'salary_basis' => ['required_unless:calculation_method,fixed', 'nullable', 'string', 'max:255'],
            'employee_rate' => ['required_if:calculation_method,percentage', 'nullable', 'numeric', 'min:0', 'max:100'],
            'employer_rate' => ['required_if:calculation_method,percentage', 'nullable', 'numeric', 'min:0', 'max:100'],
            'employee_amount' => ['required_if:calculation_method,fixed', 'nullable', 'numeric', 'min:0'],
            'employer_amount' => ['required_if:calculation_method,fixed', 'nullable', 'numeric', 'min:0'],
            'frequency' => ['required_if:calculation_method,fixed', 'nullable', Rule::in(self::FREQUENCIES)],
            'minimum_salary_base' => ['nullable', 'numeric', 'min:0'],
            'maximum_salary_base' => [
                'nullable', 'numeric', 'min:0',
                function (string $attribute, mixed $value, \Closure $fail) {
                    $minimum = request()->input('minimum_salary_base');
                    if ($value !== null && $minimum !== null && $minimum !== '' && (float) $value < (float) $minimum) {
                        $fail('The maximum salary must be greater than or equal to the minimum salary.');
                    }
                },
            ],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_status' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Sanitize payload based on calculation method so conflicting data is cleared.
     */
    private function sanitizeByMethod(array $validated): array
    {
        $method = $validated['calculation_method'] ?? null;

        if ($method === 'percentage') {
            $validated['employee_amount'] = null;
            $validated['employer_amount'] = null;
            $validated['frequency'] = null;
        }

        if ($method === 'fixed') {
            $validated['salary_basis'] = null;
            $validated['employee_rate'] = null;
            $validated['employer_rate'] = null;
            $validated['minimum_salary_base'] = null;
            $validated['maximum_salary_base'] = null;
        }

        if ($method === 'bracket') {
            $validated['employee_rate'] = null;
            $validated['employer_rate'] = null;
            $validated['employee_amount'] = null;
            $validated['employer_amount'] = null;
            $validated['frequency'] = null;
        }

        return $validated;
    }

    public function index(Request $request): JsonResponse
    {
        $query = ContributionRateSchedule::query()
            ->with(self::RELATIONS)
            ->withCount('brackets');

        if ($request->filled('contribution_type_id')) {
            $query->where('contribution_type_id', $request->integer('contribution_type_id'));
        }

        if ($request->filled('calculation_method')) {
            $query->where('calculation_method', $request->string('calculation_method')->toString());
        }

        if ($request->filled('status')) {
            $status = strtolower($request->string('status')->trim()->toString());
            $query->where('is_status', in_array($status, ['active', '1', 'true'], true));
        }

        return response()->json([
            'success' => true,
            'message' => 'Contribution rate schedules retrieved successfully.',
            'data' => $query->orderByDesc('effective_from')->orderByDesc('id')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->sanitizeByMethod($request->validate($this->rules()));
        $reason = $validated['reason'] ?? null;
        unset($validated['reason']);

        $hasPriorSchedule = ContributionRateSchedule::query()
            ->where('contribution_type_id', $validated['contribution_type_id'])
            ->exists();

        $schedule = ContributionRateSchedule::create([
            ...$validated,
            'is_status' => $validated['is_status'] ?? true,
        ]);

        $action = $hasPriorSchedule ? 'versioned' : 'created';

        ContributionChangeLog::record([
            'contribution_type_id' => $schedule->contribution_type_id,
            'contribution_rate_schedule_id' => $schedule->id,
            'entity_type' => 'rate_schedule',
            'entity_id' => $schedule->id,
            'action' => $action,
            'old_values' => null,
            'new_values' => $this->snapshot($schedule),
            'reason' => $reason ?? ($action === 'versioned'
                ? "Created new rate schedule version ({$schedule->calculation_method}) effective {$schedule->effective_from?->format('Y-m-d')}."
                : "Created initial rate schedule ({$schedule->calculation_method}) effective {$schedule->effective_from?->format('Y-m-d')}."),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Contribution rate schedule created successfully.',
            'data' => $schedule->load(self::RELATIONS),
        ], 201);
    }

    public function show(ContributionRateSchedule $contributionRateSchedule): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Contribution rate schedule retrieved successfully.',
            'data' => $contributionRateSchedule->load(self::RELATIONS),
        ]);
    }

    public function update(Request $request, ContributionRateSchedule $contributionRateSchedule): JsonResponse
    {
        $validated = $this->sanitizeByMethod($request->validate($this->rules()));
        $reason = $validated['reason'] ?? null;
        unset($validated['reason']);

        $oldValues = $this->snapshot($contributionRateSchedule);

        $contributionRateSchedule->update([
            ...$validated,
            'is_status' => $validated['is_status'] ?? $contributionRateSchedule->is_status,
        ]);

        $fresh = $contributionRateSchedule->fresh();
        $newValues = $this->snapshot($fresh);

        if ($oldValues['is_status'] === true && $newValues['is_status'] === false) {
            $action = 'deactivated';
            $defaultReason = "Deactivated rate schedule #{$fresh->id}.";
        } elseif ($oldValues['effective_from'] !== $newValues['effective_from']) {
            $action = 'versioned';
            $defaultReason = "Updated effective date from {$oldValues['effective_from']} to {$newValues['effective_from']}.";
        } else {
            $action = 'updated';
            $defaultReason = "Updated rate schedule ({$fresh->calculation_method}) effective {$newValues['effective_from']}.";
        }

        ContributionChangeLog::record([
            'contribution_type_id' => $fresh->contribution_type_id,
            'contribution_rate_schedule_id' => $fresh->id,
            'entity_type' => 'rate_schedule',
            'entity_id' => $fresh->id,
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'reason' => $reason ?? $defaultReason,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Contribution rate schedule updated successfully.',
            'data' => $fresh->load(self::RELATIONS),
        ]);
    }

    public function destroy(Request $request, ContributionRateSchedule $contributionRateSchedule): JsonResponse
    {
        if (
            $contributionRateSchedule->brackets()->exists() ||
            $contributionRateSchedule->payrollContributions()->exists()
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete this contribution rate schedule because it has connected rate brackets or payroll contributions.',
            ], 409);
        }

        $oldValues = $this->snapshot($contributionRateSchedule);
        $scheduleId = $contributionRateSchedule->id;
        $typeId = $contributionRateSchedule->contribution_type_id;

        DB::transaction(function () use ($contributionRateSchedule, $scheduleId, $typeId, $oldValues, $request) {
            ContributionChangeLog::query()
                ->where('contribution_rate_schedule_id', $scheduleId)
                ->update(['contribution_rate_schedule_id' => null]);

            $contributionRateSchedule->delete();

            ContributionChangeLog::record([
                'contribution_type_id' => $typeId,
                'contribution_rate_schedule_id' => null,
                'entity_type' => 'rate_schedule',
                'entity_id' => $scheduleId,
                'action' => 'deleted',
                'old_values' => $oldValues,
                'new_values' => null,
                'reason' => $request->input('reason') ?: "Deleted rate schedule #{$scheduleId} ({$oldValues['calculation_method']}).",
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Contribution rate schedule deleted successfully.',
        ]);
    }
}
