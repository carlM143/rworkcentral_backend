<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Payroll;
use App\Models\PayrollPeriod;
use App\Services\PayslipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayslipController extends Controller
{
    public function __construct(private PayslipService $payslips) {}

    private function companyId(Request $request): int
    {
        abort_unless($request->user(), 401, 'Unauthenticated.');

        if ($request->user()->company_id) {
            return (int) $request->user()->company_id;
        }

        return (int) Company::query()->sole()->id;
    }

    private function findPeriod(Request $request, int $periodId): PayrollPeriod
    {
        return PayrollPeriod::query()
            ->where('company_id', $this->companyId($request))
            ->findOrFail($periodId);
    }

    // GET /api/admin/payslips?payroll_period_id=1
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payroll_period_id' => ['required', 'integer', 'exists:payroll_periods,id'],
        ]);

        $period = $this->findPeriod($request, (int) $data['payroll_period_id']);

        $payslips = Payroll::query()
            ->where('payroll_period_id', $period->id)
            ->where('status', 'finalized')
            ->with([
                'employee:id,emp_id,firstname,lastname,department_id',
                'employee.department',
                'payrollGroup:id,group_name',
            ])
            ->get()
            ->sortBy(fn ($p) => "{$p->employee?->lastname} {$p->employee?->firstname}")
            ->values()
            ->map(fn (Payroll $p) => [
                'payroll_id' => $p->id,
                'payslip_no' => $this->payslips->payslipNo($p, $period),
                'emp_id' => $p->employee?->emp_id,
                'name' => trim("{$p->employee?->lastname}, {$p->employee?->firstname}"),
                'department' => $p->employee?->department?->department_name,
                'payroll_group' => $p->payrollGroup?->group_name,
                'gross_pay' => (float) $p->gross_pay,
                'total_deductions' => (float) $p->total_deductions,
                'net_pay' => (float) $p->net_pay,
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Payslips retrieved successfully.',
            'data' => [
                'period' => $this->payslips->periodData($period),
                'payslips' => $payslips,
            ],
        ]);
    }

    // GET /api/admin/payslips/{payroll}
    public function show(Request $request, Payroll $payroll): JsonResponse
    {
        $period = $this->findPeriod($request, (int) $payroll->payroll_period_id);

        abort_unless(
            $payroll->status === 'finalized',
            422,
            'Payslips are available after the payroll is finalized.'
        );

        return response()->json([
            'success' => true,
            'message' => 'Payslip retrieved successfully.',
            'data' => $this->payslips->detail($payroll, $period),
        ]);
    }
}
