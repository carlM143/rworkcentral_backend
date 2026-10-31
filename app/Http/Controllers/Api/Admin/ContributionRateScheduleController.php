<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContributionRateSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContributionRateScheduleController extends Controller
{
    /**
     * Relationships returned with every rate schedule.
     */
    private const RELATIONS = 'contributionType:id,contribution_code,contribution_name,provider,is_status';

    /**
     * Validation rules shared by store and update.
     */
    private function rules(): array
    {
        return [
            'contribution_type_id' => ['required', 'integer', 'exists:contribution_types,id'],
            'calculation_method' => ['required', Rule::in(['percentage', 'bracket', 'fixed'])],
            'salary_basis' => ['required', 'string', 'max:255'],
            'employee_rate' => ['nullable', 'numeric', 'min:0'],
            'employer_rate' => ['nullable', 'numeric', 'min:0'],
            'minimum_salary_base' => ['nullable', 'numeric', 'min:0'],
            'maximum_salary_base' => ['nullable', 'numeric', 'min:0'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_status' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Sanitize payload based on calculation method so conflicting data is cleared.
     */
    private function sanitizeByMethod(array $validated): array
    {
        $method = $validated['calculation_method'] ?? null;

        if ($method === 'bracket') {
            $validated['employee_rate'] = null;
            $validated['employer_rate'] = null;
        }

        return $validated;
    }

    public function index(Request $request): JsonResponse
    {
        $query = ContributionRateSchedule::query()->with(self::RELATIONS);

        if ($request->filled('contribution_type_id')) {
            $query->where('contribution_type_id', $request->integer('contribution_type_id'));
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

        $schedule = ContributionRateSchedule::create([
            ...$validated,
            'is_status' => $validated['is_status'] ?? true,
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

        $contributionRateSchedule->update([
            ...$validated,
            'is_status' => $validated['is_status'] ?? $contributionRateSchedule->is_status,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Contribution rate schedule updated successfully.',
            'data' => $contributionRateSchedule->fresh()->load(self::RELATIONS),
        ]);
    }

    public function destroy(ContributionRateSchedule $contributionRateSchedule): JsonResponse
    {
        if ($contributionRateSchedule->brackets()->exists()
            || $contributionRateSchedule->payrollContributions()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete this rate schedule because it already has rate brackets or payroll contributions.',
            ], 409);
        }

        $contributionRateSchedule->delete();

        return response()->json([
            'success' => true,
            'message' => 'Contribution rate schedule deleted successfully.',
        ]);
    }
}
