<?php

namespace App\Http\Controllers\Api\Employee;

use App\Http\Controllers\Controller;
use App\Models\Payroll;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeePayslipController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $employee = $request->user()?->employee;

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'No employee profile associated with this account.',
            ], 404);
        }

        $payrolls = $employee->payrolls()
            ->with(['payrollPeriod', 'payrollGroup', 'salaryType'])
            ->latest('id')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Payslips retrieved successfully.',
            'data' => $payrolls,
        ]);
    }

    public function show(Payroll $payroll, Request $request): JsonResponse
    {
        $employee = $request->user()?->employee;

        if (! $employee || $payroll->employee_id !== $employee->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized action.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'Payslip details retrieved successfully.',
            'data' => $payroll->load(['payrollPeriod', 'payrollGroup', 'salaryType']),
        ]);
    }
}
