<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeaveTypesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $types = LeaveType::where('user_id', $request->user()->id)
            ->withCount(['leavePolicies', 'leaveRequests'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->input('search');
                $query->where(fn ($q) => $q->where('leave_name', 'like', "%{$search}%")
                    ->orWhere('leave_code', 'like', "%{$search}%"));
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->latest('id')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Leave types retrieved successfully.',
            'data' => $types,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge([
            'leave_code' => strtoupper(trim((string) $request->input('leave_code'))),
        ]);

        $data = $request->validate([
            'leave_code' => ['required', 'string', 'max:50', Rule::unique('leave_types', 'leave_code')
                ->where('user_id', $request->user()->id)],
            'leave_name' => ['required', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        $type = LeaveType::create([
            ...$data,
            'user_id' => $request->user()->id,
            'status' => $data['status'] ?? 'active',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Leave type created successfully.',
            'data' => $type,
        ], 201);
    }

    public function show(Request $request, LeaveType $leaveType): JsonResponse
    {
        $this->authorizeType($request, $leaveType);

        return response()->json([
            'success' => true,
            'message' => 'Leave type retrieved successfully.',
            'data' => $leaveType->load('leavePolicies.employmentType'),
        ]);
    }

    public function update(Request $request, LeaveType $leaveType): JsonResponse
    {
        $this->authorizeType($request, $leaveType);

        if ($request->exists('leave_code')) {
            $request->merge([
                'leave_code' => strtoupper(trim((string) $request->input('leave_code'))),
            ]);
        }

        $data = $request->validate([
            'leave_code' => ['sometimes', 'required', 'string', 'max:50',
                Rule::unique('leave_types', 'leave_code')
                    ->where('user_id', $request->user()->id)
                    ->ignore($leaveType->id)],
            'leave_name' => ['sometimes', 'required', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        $leaveType->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Leave type updated successfully.',
            'data' => $leaveType->fresh(),
        ]);
    }

    public function destroy(Request $request, LeaveType $leaveType): JsonResponse
    {
        $this->authorizeType($request, $leaveType);

        if ($leaveType->leavePolicies()->exists() || $leaveType->leaveRequests()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This leave type has associated policies or requests and cannot be deleted.',
            ], 409);
        }

        $leaveType->delete();

        return response()->json([
            'success' => true,
            'message' => 'Leave type deleted successfully.',
        ]);
    }

    private function authorizeType(Request $request, LeaveType $leaveType): void
    {
        abort_unless((int) $leaveType->user_id === (int) $request->user()->id, 404);
    }
}
