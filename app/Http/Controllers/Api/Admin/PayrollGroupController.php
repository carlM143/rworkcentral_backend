<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PayrollGroup;
use Illuminate\Http\JsonResponse;

class PayrollGroupController extends Controller
{
    public function index(): JsonResponse
    {
        $payrollGroups = PayrollGroup::query()
            ->select('id', 'group_name')
            ->orderBy('group_name')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Payroll groups retrieved successfully.',
            'data' => $payrollGroups,
        ]);
    }
}
