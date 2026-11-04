<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
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

        $setting = ContributionDeductionSetting::create([
            ...$validated,
            'deduction_schedule' => $this->resolveDeductionSchedule($validated),
            'is_status' => $validated['is_status'] ?? true,
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

        $contributionDeductionSetting->update([
            ...$validated,
            'deduction_schedule' => $this->resolveDeductionSchedule($validated),
            'is_status' => $validated['is_status'] ?? $contributionDeductionSetting->is_status,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Contribution deduction setting updated successfully.',
            'data' => $contributionDeductionSetting->fresh()->load(self::RELATIONS),
        ]);
    }

    public function destroy(ContributionDeductionSetting $contributionDeductionSetting): JsonResponse
    {
        $contributionDeductionSetting->delete();

        return response()->json([
            'success' => true,
            'message' => 'Contribution deduction setting deleted successfully.',
        ]);
    }
}
