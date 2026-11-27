<?php

namespace App\Http\Controllers\Api\Employee;

use App\Http\Controllers\Controller;
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

        $isHead = $user->hasPrivilege('approvals', 'first_level.view') && $user->hasPrivilege('approvals', 'first_level.approve');

        $requests = $isHead
            ? ObaRequest::with(['obaEmployees', 'images', 'filer:id,firstname,lastname'])
                ->whereIn('id', \Illuminate\Support\Facades\DB::table('department_request_approvals')
                    ->select('request_id')->where('request_type', 'oba')->where('user_id', $user->id)->where('status', 'pending'))
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
        return app(\App\Http\Controllers\Api\Admin\SupervisorApprovalController::class)->approve($request, 'oba', $obaRequest->id);
    }

    // PATCH /oba-requests/{obaRequest}/dept-head/reject
    public function reject(Request $request, ObaRequest $obaRequest): JsonResponse
    {
        $request->merge(['remarks' => $request->input('reason')]);
        return app(\App\Http\Controllers\Api\Admin\SupervisorApprovalController::class)->reject($request, 'oba', $obaRequest->id);
    }
}
