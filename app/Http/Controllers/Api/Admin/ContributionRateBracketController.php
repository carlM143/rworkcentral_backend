<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContributionRateBracket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContributionRateBracketController extends Controller
{
    private const RELATIONS = [
        'rateSchedule:id,contribution_type_id,calculation_method,salary_basis,effective_from',
        'rateSchedule.contributionType:id,contribution_code,contribution_name,provider',
    ];

    private function rules(): array
    {
        return [
            'contribution_rate_schedule_id' => ['required', 'integer', 'exists:contribution_rate_schedules,id'],
            'salary_from' => ['required', 'numeric', 'min:0'],
            'salary_to' => ['nullable', 'numeric', 'gte:salary_from'],
            'monthly_salary_credit' => ['nullable', 'numeric', 'min:0'],
            'employee_rate' => ['nullable', 'numeric', 'min:0'],
            'employer_rate' => ['nullable', 'numeric', 'min:0'],
            'employee_amount' => ['nullable', 'numeric', 'min:0'],
            'employer_amount' => ['nullable', 'numeric', 'min:0'],
            'employer_ec_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $query = ContributionRateBracket::query()->with(self::RELATIONS);

        if ($request->filled('contribution_rate_schedule_id')) {
            $query->where('contribution_rate_schedule_id', $request->integer('contribution_rate_schedule_id'));
        }

        return response()->json([
            'success' => true,
            'message' => 'Contribution rate brackets retrieved successfully.',
            'data' => $query->orderBy('contribution_rate_schedule_id')->orderBy('salary_from')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        $bracket = ContributionRateBracket::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Contribution rate bracket created successfully.',
            'data' => $bracket->load(self::RELATIONS),
        ], 201);
    }

    public function show(ContributionRateBracket $contributionRateBracket): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Contribution rate bracket retrieved successfully.',
            'data' => $contributionRateBracket->load(self::RELATIONS),
        ]);
    }

    public function update(Request $request, ContributionRateBracket $contributionRateBracket): JsonResponse
    {
        $validated = $request->validate($this->rules());

        $contributionRateBracket->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Contribution rate bracket updated successfully.',
            'data' => $contributionRateBracket->fresh()->load(self::RELATIONS),
        ]);
    }

    public function destroy(ContributionRateBracket $contributionRateBracket): JsonResponse
    {
        $contributionRateBracket->delete();

        return response()->json([
            'success' => true,
            'message' => 'Contribution rate bracket deleted successfully.',
        ]);
    }
}
