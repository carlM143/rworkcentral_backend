<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContributionChangeLog;
use App\Models\ContributionDeductionSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ContributionDeductionSettingController extends Controller
{
    private const RELATIONS = [
        'company:id,company_name',
        'contributionType:id,contribution_code,contribution_name,provider',
    ];

    private function snapshot(ContributionDeductionSetting $setting): array
    {
        return [
            'company_id' => $setting->company_id,
            'contribution_type_id' => $setting->contribution_type_id,
            'first_cutoff_percent' => $setting->first_cutoff_percent,
            'second_cutoff_percent' => $setting->second_cutoff_percent,
            'effective_from' => $setting->effective_from?->format('Y-m-d'),
            'effective_to' => $setting->effective_to?->format('Y-m-d'),
            'is_status' => (bool) $setting->is_status,
        ];
    }

    private function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'contribution_type_id' => ['required', 'integer', 'exists:contribution_types,id'],
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
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_status' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function scheduleLabel(ContributionDeductionSetting $setting): string
    {
        $first = (float) $setting->first_cutoff_percent;
        $second = (float) $setting->second_cutoff_percent;

        return match (true) {
            $first >= 100 => 'first cutoff only',
            $second >= 100 => 'second cutoff only',
            default => "{$first}% / {$second}% split",
        };
    }

    private function guardOverlap(array $validated, ?int $ignoreId = null): void
    {
        $from = $validated['effective_from'];
        $to = $validated['effective_to'] ?? null;

        $overlap = ContributionDeductionSetting::query()
            ->where('company_id', $validated['company_id'])
            ->where('contribution_type_id', $validated['contribution_type_id'])
            ->where('is_status', true)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->when($to, fn ($q) => $q->whereDate('effective_from', '<=', $to))
            ->where(fn ($q) => $q->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $from))
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'effective_from' => 'This contribution already has an active deduction setting for these dates. End the existing one first.',
            ]);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $query = ContributionDeductionSetting::query()->with(self::RELATIONS);

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->integer('company_id'));
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

        $validated['is_status'] = $validated['is_status'] ?? true;

        if ($validated['is_status']) {
            $this->guardOverlap($validated);
        }

        $setting = ContributionDeductionSetting::create($validated);

        ContributionChangeLog::record([
            'contribution_type_id' => $setting->contribution_type_id,
            'company_id' => $setting->company_id,
            'entity_type' => 'deduction_setting',
            'entity_id' => $setting->id,
            'action' => 'created',
            'old_values' => null,
            'new_values' => $this->snapshot($setting),
            'reason' => $reason ?? "Created deduction setting ({$this->scheduleLabel($setting)}) effective {$setting->effective_from?->format('Y-m-d')}.",
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

        $validated['is_status'] = $validated['is_status'] ?? $contributionDeductionSetting->is_status;

        if ($validated['is_status']) {
            $this->guardOverlap($validated, $contributionDeductionSetting->id);
        }

        $oldValues = $this->snapshot($contributionDeductionSetting);

        $contributionDeductionSetting->update($validated);

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
            $defaultReason = "Updated deduction setting ({$this->scheduleLabel($fresh)}).";
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
        $label = $this->scheduleLabel($contributionDeductionSetting);

        $contributionDeductionSetting->delete();

        ContributionChangeLog::record([
            'contribution_type_id' => $typeId,
            'company_id' => $companyId,
            'entity_type' => 'deduction_setting',
            'entity_id' => $settingId,
            'action' => 'deleted',
            'old_values' => $oldValues,
            'new_values' => null,
            'reason' => $request->input('reason') ?: "Deleted deduction setting #{$settingId} ({$label}).",
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Contribution deduction setting deleted successfully.',
        ]);
    }
}