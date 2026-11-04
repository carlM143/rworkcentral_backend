<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContributionRateBracket;
use App\Models\ContributionRateSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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

    /**
     * Ensure the bracket belongs to a bracket-based schedule and that its salary
     * range does not overlap another bracket of the same schedule.
     */
    private function guardBracket(array $validated, ?int $ignoreId = null): void
    {
        $schedule = ContributionRateSchedule::find($validated['contribution_rate_schedule_id']);

        if ($schedule && $schedule->calculation_method !== 'bracket') {
            throw ValidationException::withMessages([
                'contribution_rate_schedule_id' => 'Brackets can only be added to a bracket-based rate schedule.',
            ]);
        }

        $from = (float) $validated['salary_from'];
        $to = isset($validated['salary_to']) ? (float) $validated['salary_to'] : null;

        $overlap = ContributionRateBracket::query()
            ->where('contribution_rate_schedule_id', $validated['contribution_rate_schedule_id'])
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where(function ($query) use ($to) {
                if ($to !== null) {
                    $query->where('salary_from', '<=', $to);
                }
            })
            ->where(function ($query) use ($from) {
                $query->whereNull('salary_to')->orWhere('salary_to', '>=', $from);
            })
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'salary_from' => 'This salary range overlaps an existing bracket in the same schedule.',
            ]);
        }
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
        $this->guardBracket($validated);

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
        $this->guardBracket($validated, $contributionRateBracket->id);

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
