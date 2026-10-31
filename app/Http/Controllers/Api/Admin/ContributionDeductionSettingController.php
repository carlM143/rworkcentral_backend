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
        'contributionType:id,contribution_code,contribution_name,provider',
    ];

    private function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'contribution_type_id' => ['required', 'integer', 'exists:contribution_types,id'],
            'first_cutoff_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'second_cutoff_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_status' => ['nullable', 'boolean'],
        ];
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
            'data' => $query->orderByDesc('effective_from')->orderByDesc('id')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        $setting = ContributionDeductionSetting::create([
            ...$validated,
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
