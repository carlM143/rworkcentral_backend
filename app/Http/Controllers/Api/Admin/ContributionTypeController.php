<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContributionRateBracket;
use App\Models\ContributionType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ContributionTypeController extends Controller
{
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
        ]);

        $contributionType = ContributionType::create([
            'contribution_code' => $validated['contribution_code'],
            'contribution_name' => $validated['contribution_name'],
            'provider' => $validated['provider'],
            'is_status' => $validated['is_status'] ?? true,
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
        ]);

        $contributionType->update([
            'contribution_code' => $validated['contribution_code'],
            'contribution_name' => $validated['contribution_name'],
            'provider' => $validated['provider'],
            'is_status' => $validated['is_status'] ?? $contributionType->is_status,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Contribution type updated successfully.',
            'data' => $contributionType->fresh(),
        ]);
    }

    public function destroy(ContributionType $contributionType): JsonResponse
    {
        DB::transaction(function () use ($contributionType) {
            $scheduleIds = $contributionType->rateSchedules()->pluck('id');

            if ($scheduleIds->isNotEmpty()) {
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
