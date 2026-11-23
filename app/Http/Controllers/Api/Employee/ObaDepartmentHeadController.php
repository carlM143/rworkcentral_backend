<?php

namespace App\Http\Controllers\Api\Employee;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\ObaRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ObaDepartmentHeadController extends Controller
{
    // GET /oba-requests/approvals?status=pending
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user, 401, 'Unauthenticated.');

        $isHead = Department::where('admin_id', $user->id)->exists();

        $requests = $isHead
            ? ObaRequest::with(['obaEmployees', 'images', 'filer:id,firstname,lastname'])
                ->where('dept_head_id', $user->id)
                ->where('status', $request->input('status', ObaRequest::STATUS_PENDING))
                ->orderBy('date_request')
                ->orderBy('id')
                ->get()
            : collect();

        return response()->json([
            'success' => true,
            'message' => 'Approvals retrieved successfully.',
            'is_department_head' => $isHead,
            'data' => $requests,
        ]);
    }

    // PATCH /oba-requests/{obaRequest}/dept-head/approve
    public function approve(Request $request, ObaRequest $obaRequest): JsonResponse
    {
        if ($error = $this->guard($request, $obaRequest)) {
            return $error;
        }

        $obaRequest->update([
            'status' => ObaRequest::STATUS_DEPT_HEAD_APPROVED,
            'dept_head_acted_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'OBA request approved. It now goes to HR.',
            'data' => $obaRequest->fresh(['obaEmployees', 'images', 'filer:id,firstname,lastname']),
        ]);
    }

    // PATCH /oba-requests/{obaRequest}/dept-head/reject
    public function reject(Request $request, ObaRequest $obaRequest): JsonResponse
    {
        if ($error = $this->guard($request, $obaRequest)) {
            return $error;
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $obaRequest->update([
            'status' => ObaRequest::STATUS_REJECTED,
            'dept_head_acted_at' => now(),
            'rejection_reason' => $validated['reason'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'OBA request rejected.',
            'data' => $obaRequest->fresh(['obaEmployees', 'images', 'filer:id,firstname,lastname']),
        ]);
    }

    private function guard(Request $request, ObaRequest $obaRequest): ?JsonResponse
    {
        $user = $request->user();

        abort_unless($user, 401, 'Unauthenticated.');

        if ((int) $obaRequest->dept_head_id !== (int) $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'OBA request not found.',
            ], 404);
        }

        if ($obaRequest->status !== ObaRequest::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'This OBA request is no longer waiting for your approval.',
            ], 422);
        }

        return null;
    }
}