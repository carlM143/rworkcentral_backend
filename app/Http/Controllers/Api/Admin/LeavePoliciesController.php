<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeavePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LeavePoliciesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $policies = LeavePolicy::query()
            ->whereHas('leaveType', fn ($q) => $q->where('user_id', $request->user()->id))
            ->with(['leaveType', 'employmentType'])
            ->when($request->filled('leave_type_id'), fn ($q) => $q->where('leave_type_id', $request->input('leave_type_id')))
            ->when($request->filled('employment_type_id'), fn ($q) => $q->where('employment_type_id', $request->input('employment_type_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->latest('id')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Leave policies retrieved successfully.',
            'data' => $policies,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules($request));

        $data['status'] = $data['status'] ?? 'pending';

        $policy = LeavePolicy::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Leave policy created successfully.',
            'data' => $policy->load(['leaveType', 'employmentType']),
        ], 201);
    }

    public function show(Request $request, LeavePolicy $leavePolicy): JsonResponse
    {
        $this->authorizePolicy($request, $leavePolicy);

        return response()->json([
            'success' => true,
            'message' => 'Leave policy retrieved successfully.',
            'data' => $leavePolicy->load(['leaveType', 'employmentType']),
        ]);
    }

    public function update(Request $request, LeavePolicy $leavePolicy): JsonResponse
    {
        $this->authorizePolicy($request, $leavePolicy);

        $data = $request->validate($this->rules($request, true));

        $start = $data['effective_from'] ?? $leavePolicy->effective_from?->format('Y-m-d');
        $end = array_key_exists('effective_to', $data)
            ? $data['effective_to']
            : $leavePolicy->effective_to?->format('Y-m-d');

        if ($start && $end && $end < $start) {
            throw ValidationException::withMessages([
                'effective_to' => 'Effective To must be on or after Effective From.',
            ]);
        }

        $leavePolicy->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Leave policy updated successfully.',
            'data' => $leavePolicy->fresh()->load(['leaveType', 'employmentType']),
        ]);
    }

    public function destroy(Request $request, LeavePolicy $leavePolicy): JsonResponse
    {
        $this->authorizePolicy($request, $leavePolicy);

        $leavePolicy->delete();

        return response()->json([
            'success' => true,
            'message' => 'Leave policy deleted successfully.',
        ]);
    }

    private function rules(Request $request, bool $update = false): array
    {
        $required = $update ? 'sometimes' : 'required';

        return [
            'leave_type_id' => [
                $required, 'integer',
                Rule::exists('leave_types', 'id')->where('user_id', $request->user()->id),
            ],
            'employment_type_id' => [$required, 'integer', Rule::exists('employment_types', 'id')],
            'is_eligible' => [$required, 'boolean'],
            'is_paid' => [$required, 'boolean'],
            'annual_credits' => [$required, 'numeric', 'min:0'],
            'min_service_months' => [$required, 'integer', 'min:0'],
            'allow_carry_over' => [$required, 'boolean'],
            'max_carry_over_days' => [$required, 'numeric', 'min:0'],
            'requires_approval' => [$required, 'boolean'],
            'usage_window' => [$required, 'string', 'max:100'],
            'validity_period' => [$required, 'string', 'max:100'],
            'effective_from' => [$required, 'date'],
            'effective_to' => ['nullable', 'date', ...($update ? [] : ['after_or_equal:effective_from'])],
            'status' => ['sometimes', Rule::in(['approved', 'pending'])],
        ];
    }

    private function authorizePolicy(Request $request, LeavePolicy $leavePolicy): void
    {
        abort_unless(
            $leavePolicy->leaveType()->where('user_id', $request->user()->id)->exists(),
            404
        );
    }
}
