<?php

namespace App\Http\Controllers\Api\Employee;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Payroll;
use App\Services\PayslipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The logged-in employee's own payslips.
 * Only payslips HR has sent (Admin › Employee Payroll › Send payslips) are visible.
 */
class EmployeePayslipController extends Controller
{
    public function __construct(private PayslipService $payslips) {}

    // GET /api/employee/payslips?year=2026
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        $employee = $this->employee($request);

        if (! $employee) {
            return $this->noEmployee();
        }

        $payrolls = $employee->payrolls()
            ->whereNotNull('payslip_sent_at')
            ->where('status', 'finalized')
            ->with('payrollPeriod')
            ->when($data['year'] ?? null, fn ($q, $year) => $q->whereHas(
                'payrollPeriod',
                fn ($p) => $p->whereYear('pay_period_start', $year)
            ))
            ->get()
            ->filter(fn (Payroll $p) => $p->payrollPeriod !== null)
            ->sortByDesc(fn (Payroll $p) => $p->payrollPeriod->cutoff_end?->format('Y-m-d') . sprintf('-%010d', $p->id))
            ->values();

        $rows = $payrolls->map(fn (Payroll $p) => [
            'payroll_id' => $p->id,
            'payslip_no' => $this->payslips->payslipNo($p, $p->payrollPeriod),
            'period' => $this->payslips->periodData($p->payrollPeriod),
            'gross_pay' => (float) $p->gross_pay,
            'total_deductions' => (float) $p->total_deductions,
            'net_pay' => (float) $p->net_pay,
            'sent_at' => $p->payslip_sent_at?->toIso8601String(),
        ]);

        // Years with payslips, for the year filter
        $years = $employee->payrolls()
            ->whereNotNull('payslip_sent_at')
            ->where('status', 'finalized')
            ->with('payrollPeriod:id,pay_period_start')
            ->get()
            ->map(fn (Payroll $p) => $p->payrollPeriod?->pay_period_start?->year)
            ->filter()
            ->unique()
            ->sortDesc()
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Payslips retrieved successfully.',
            'data' => [
                'payslips' => $rows,
                'years' => $years,
                'totals' => [
                    'count' => $rows->count(),
                    'gross_pay' => round($rows->sum('gross_pay'), 2),
                    'net_pay' => round($rows->sum('net_pay'), 2),
                ],
            ],
        ]);
    }

    // GET /api/employee/payslips/{payroll}
    public function show(Payroll $payroll, Request $request): JsonResponse
    {
        $employee = $this->employee($request);

        if (! $employee) {
            return $this->noEmployee();
        }

        // Someone else's payslip, or one HR has not sent yet: treat as not found
        if (
            (int) $payroll->employee_id !== (int) $employee->id
            || $payroll->payslip_sent_at === null
            || $payroll->status !== 'finalized'
            || ! $payroll->payrollPeriod
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Payslip not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Payslip details retrieved successfully.',
            'data' => $this->payslips->detail($payroll, $payroll->payrollPeriod) + [
                'sent_at' => $payroll->payslip_sent_at->toIso8601String(),
            ],
        ]);
    }

    private function employee(Request $request): ?Employee
    {
        return $request->user()?->employee;
    }

    private function noEmployee(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'No employee profile associated with this account.',
        ], 404);
    }
}
