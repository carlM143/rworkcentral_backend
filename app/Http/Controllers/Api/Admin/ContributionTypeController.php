<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContributionChangeLog;
use App\Models\ContributionRateBracket;
use App\Models\ContributionType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ContributionTypeController extends Controller
{
    private function snapshot(ContributionType $type): array
    {
        return [
            'contribution_code' => $type->contribution_code,
            'contribution_name' => $type->contribution_name,
            'provider' => $type->provider,
            'is_status' => (bool) $type->is_status,
        ];
    }
    public function index(Request $request): JsonResponse
    {
        $query = ContributionType::query();

        if ($request->filled('search')) {
            $search = $request->string('search')->trim()->toString();

            $query->where(function ($builder) use ($search) {
                $builder->where('contribution_code', 'like', "%{$search}%")
                    ->orWhere('contribution_name', 'like', "%{$search}%")
                    ->orWhere('provider', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $status = strtolower($request->string('status')->trim()->toString());

            $query->where('is_status', in_array($status, ['active', '1', 'true'], true));
        }

        return response()->json([
            'success' => true,
            'message' => 'Contribution types retrieved successfully.',
            'data' => $query->orderBy('contribution_code')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'contribution_code' => ['required', 'string', 'max:50', 'unique:contribution_types,contribution_code'],
            'contribution_name' => ['required', 'string', 'max:150'],
            'provider' => ['required', 'string', 'max:150'],
            'is_status' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $contributionType = ContributionType::create([
            'contribution_code' => $validated['contribution_code'],
            'contribution_name' => $validated['contribution_name'],
            'provider' => $validated['provider'],
            'is_status' => $validated['is_status'] ?? true,
        ]);

        ContributionChangeLog::record([
            'contribution_type_id' => $contributionType->id,
            'entity_type' => 'contribution_type',
            'entity_id' => $contributionType->id,
            'action' => 'created',
            'old_values' => null,
            'new_values' => $this->snapshot($contributionType),
            'reason' => $validated['reason'] ?? "Created contribution type {$contributionType->contribution_code} — {$contributionType->contribution_name}.",
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Contribution type created successfully.',
            'data' => $contributionType,
        ], 201);
    }

    public function show(ContributionType $contributionType): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Contribution type retrieved successfully.',
            'data' => $contributionType,
        ]);
    }

    public function update(Request $request, ContributionType $contributionType): JsonResponse
    {
        $validated = $request->validate([
            'contribution_code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('contribution_types', 'contribution_code')->ignore($contributionType->id),
            ],
            'contribution_name' => ['required', 'string', 'max:150'],
            'provider' => ['required', 'string', 'max:150'],
            'is_status' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $oldValues = $this->snapshot($contributionType);

        $contributionType->update([
            'contribution_code' => $validated['contribution_code'],
            'contribution_name' => $validated['contribution_name'],
            'provider' => $validated['provider'],
            'is_status' => $validated['is_status'] ?? $contributionType->is_status,
        ]);

        $fresh = $contributionType->fresh();
        $newValues = $this->snapshot($fresh);
        $action = ($oldValues['is_status'] === true && $newValues['is_status'] === false)
            ? 'deactivated'
            : 'updated';

        ContributionChangeLog::record([
            'contribution_type_id' => $fresh->id,
            'entity_type' => 'contribution_type',
            'entity_id' => $fresh->id,
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'reason' => $validated['reason'] ?? ($action === 'deactivated'
                ? "Deactivated contribution type {$fresh->contribution_code}."
                : "Updated contribution type {$fresh->contribution_code}."),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Contribution type updated successfully.',
            'data' => $fresh,
        ]);
    }

    public function destroy(ContributionType $contributionType): JsonResponse
    {
        DB::transaction(function () use ($contributionType) {
            $scheduleIds = $contributionType->rateSchedules()->pluck('id');

            $contributionType->changeLogs()->delete();

            if ($scheduleIds->isNotEmpty()) {
                ContributionChangeLog::query()
                    ->whereIn('contribution_rate_schedule_id', $scheduleIds)
                    ->update(['contribution_rate_schedule_id' => null]);

                ContributionRateBracket::query()
                    ->whereIn('contribution_rate_schedule_id', $scheduleIds)
                    ->delete();
            }

            $contributionType->payrollContributions()->delete();
            $contributionType->deductionSettings()->delete();
            $contributionType->rateSchedules()->delete();
            $contributionType->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'Contribution type deleted successfully.',
        ]);
    }
}
