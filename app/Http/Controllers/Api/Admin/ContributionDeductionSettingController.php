<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContributionChangeLog;
use App\Models\ContributionDeductionSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContributionDeductionSettingController extends Controller
{
    private const RELATIONS = [
        'company:id,company_name',
        'department:id,company_id,department_code,department_name',
        'salaryType:id,type_name',
        'contributionType:id,contribution_code,contribution_name,provider',
    ];

    private function snapshot(ContributionDeductionSetting $setting): array
    {
        return [
            'company_id' => $setting->company_id,
            'department_id' => $setting->department_id,
            'salary_type_id' => $setting->salary_type_id,
            'contribution_type_id' => $setting->contribution_type_id,
            'deduction_schedule' => $setting->deduction_schedule,
            'first_cutoff_percent' => $setting->first_cutoff_percent,
            'second_cutoff_percent' => $setting->second_cutoff_percent,
            'week_1_percent' => $setting->week_1_percent,
            'week_2_percent' => $setting->week_2_percent,
            'week_3_percent' => $setting->week_3_percent,
            'week_4_percent' => $setting->week_4_percent,
            'effective_from' => $setting->effective_from?->format('Y-m-d'),
            'effective_to' => $setting->effective_to?->format('Y-m-d'),
            'is_status' => (bool) $setting->is_status,
        ];
    }

    private function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'salary_type_id' => ['nullable', 'integer', 'exists:salary_types,id'],
            'contribution_type_id' => ['required', 'integer', 'exists:contribution_types,id'],
            'deduction_schedule' => ['nullable', 'string', 'max:50'],
            'first_cutoff_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'second_cutoff_percent' => [
                'required',
                'numeric',
                'min:0',
                'max:100',
                function (string $attribute, mixed $value, \Closure $fail) {
                    $first = request()->input('first_cutoff_percent');
                    if (is_numeric($first) && is_numeric($value)) {
                        $sum = round((float) $first + (float) $value, 2);
                        if (abs($sum - 100.0) > 0.01) {
                            $fail('First cutoff and second cutoff percentages must total 100%.');
                        }
                    }
                },
            ],
            'week_1_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'week_2_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'week_3_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'week_4_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_status' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function resolveDeductionSchedule(array $validated): string
    {
        if (!empty($validated['deduction_schedule'])) {
            return (string) $validated['deduction_schedule'];
        }

        $first = round((float) ($validated['first_cutoff_percent'] ?? 0), 2);
        $second = round((float) ($validated['second_cutoff_percent'] ?? 0), 2);

        if (abs($first - 100.0) < 0.01 && abs($second) < 0.01) {
            return 'first_deduction';
        }

        if (abs($first) < 0.01 && abs($second - 100.0) < 0.01) {
            return 'second_deduction';
        }

        if (abs($first - 50.0) < 0.01 && abs($second - 50.0) < 0.01) {
            return 'split_50_50';
        }

        return 'custom_split';
    }

    public function index(Request $request): JsonResponse
    {
        $query = ContributionDeductionSetting::query()->with(self::RELATIONS);

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->integer('company_id'));
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->integer('department_id'));
        }

        if ($request->filled('salary_type_id')) {
            $query->where('salary_type_id', $request->integer('salary_type_id'));
        }

        if ($request->filled('contribution_type_id')) {
            $query->where('contribution_type_id', $request->integer('contribution_type_id'));
        }

        if ($request->filled('status')) {
            $status = strtolower($request->string('status')->trim()->toString());
            $query->where('is_status', in_array($status, ['active', '1', 'true'], true));
        }

        return response()->json([
            'success' => true,
            'message' => 'Contribution deduction settings retrieved successfully.',
            'data' => $query->orderByDesc('effective_from')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());
        $reason = $validated['reason'] ?? null;
        unset($validated['reason']);

        $setting = ContributionDeductionSetting::create([
            ...$validated,
            'deduction_schedule' => $this->resolveDeductionSchedule($validated),
            'is_status' => $validated['is_status'] ?? true,
        ]);

        ContributionChangeLog::record([
            'contribution_type_id' => $setting->contribution_type_id,
            'company_id' => $setting->company_id,
            'entity_type' => 'deduction_setting',
            'entity_id' => $setting->id,
            'action' => 'created',
            'old_values' => null,
            'new_values' => $this->snapshot($setting),
            'reason' => $reason ?? "Created deduction setting ({$setting->deduction_schedule}) effective {$setting->effective_from?->format('Y-m-d')}.",
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Contribution deduction setting created successfully.',
            'data' => $setting->load(self::RELATIONS),
        ], 201);
    }

    public function show(ContributionDeductionSetting $contributionDeductionSetting): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Contribution deduction setting retrieved successfully.',
            'data' => $contributionDeductionSetting->load(self::RELATIONS),
        ]);
    }

    public function update(Request $request, ContributionDeductionSetting $contributionDeductionSetting): JsonResponse
    {
        $validated = $request->validate($this->rules());
        $reason = $validated['reason'] ?? null;
        unset($validated['reason']);

        $oldValues = $this->snapshot($contributionDeductionSetting);

        $contributionDeductionSetting->update([
            ...$validated,
            'deduction_schedule' => $this->resolveDeductionSchedule($validated),
            'is_status' => $validated['is_status'] ?? $contributionDeductionSetting->is_status,
        ]);

        $fresh = $contributionDeductionSetting->fresh();
        $newValues = $this->snapshot($fresh);

        if ($oldValues['is_status'] === true && $newValues['is_status'] === false) {
            $action = 'deactivated';
            $defaultReason = "Deactivated deduction setting #{$fresh->id}.";
        } elseif ($oldValues['effective_from'] !== $newValues['effective_from']) {
            $action = 'versioned';
            $defaultReason = "Updated deduction setting effective date from {$oldValues['effective_from']} to {$newValues['effective_from']}.";
        } else {
            $action = 'updated';
            $defaultReason = "Updated deduction setting ({$fresh->deduction_schedule}).";
        }

        ContributionChangeLog::record([
            'contribution_type_id' => $fresh->contribution_type_id,
            'company_id' => $fresh->company_id,
            'entity_type' => 'deduction_setting',
            'entity_id' => $fresh->id,
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'reason' => $reason ?? $defaultReason,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Contribution deduction setting updated successfully.',
            'data' => $fresh->load(self::RELATIONS),
        ]);
    }

    public function destroy(Request $request, ContributionDeductionSetting $contributionDeductionSetting): JsonResponse
    {
        $oldValues = $this->snapshot($contributionDeductionSetting);
        $settingId = $contributionDeductionSetting->id;
        $typeId = $contributionDeductionSetting->contribution_type_id;
        $companyId = $contributionDeductionSetting->company_id;

        $contributionDeductionSetting->delete();

        ContributionChangeLog::record([
            'contribution_type_id' => $typeId,
            'company_id' => $companyId,
            'entity_type' => 'deduction_setting',
            'entity_id' => $settingId,
            'action' => 'deleted',
            'old_values' => $oldValues,
            'new_values' => null,
            'reason' => $request->input('reason') ?: "Deleted deduction setting #{$settingId} ({$oldValues['deduction_schedule']}).",
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Contribution deduction setting deleted successfully.',
        ]);
    }
}
