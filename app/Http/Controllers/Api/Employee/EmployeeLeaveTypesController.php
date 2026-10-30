<?php

namespace App\Http\Controllers\Api\Employee;

use App\Http\Controllers\Controller;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;

class EmployeeLeaveTypesController extends Controller
{
    // GET /api/employee/leave-types
    public function index(): JsonResponse
    {
        $leaveTypes = LeaveType::query()
            ->where('status', 'active')
            ->orderBy('leave_name')
            ->get([
                'id',
                'leave_code',
                'leave_name',
                'status',
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Employee leave types retrieved successfully.',
            'data' => $leaveTypes,
        ]);
    }
}
