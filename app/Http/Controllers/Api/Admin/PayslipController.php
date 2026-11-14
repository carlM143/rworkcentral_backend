<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Payroll;
use App\Models\PayrollPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayslipController extends Controller
{
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

    private function periodData(PayrollPeriod $period): array
    {
        return [
            'id' => $period->id,
            'period_name' => $period->period_name,
            'cutoff_number' => (int) $period->cutoff_number,
            'pay_period_start' => $period->pay_period_start?->format('Y-m-d'),
            'pay_period_end' => $period->pay_period_end?->format('Y-m-d'),
            'cutoff_start' => $period->cutoff_start?->format('Y-m-d'),
            'cutoff_end' => $period->cutoff_end?->format('Y-m-d'),
            'payment_date' => $period->payment_date?->format('Y-m-d'),
            'status' => $period->status,
        ];
    }

    private function employeeName($employee): string
    {
        return $employee ? trim("{$employee->firstname} {$employee->lastname}") : 'Employee';
    }

    private function payslipNo(Payroll $payroll, PayrollPeriod $period): string
    {
        $date = ($period->pay_period_start ?? $period->cutoff_end)->format('Ym');

        return sprintf('PS-%s-%d-%05d', $date, (int) $period->cutoff_number, $payroll->id);
    }

    private function minutesLabel(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $h ? trim("{$h}h " . ($m ? "{$m}m" : '')) : "{$m}m";
    }

    private function maskAccount(?string $number): ?string
    {
        if (!$number) {
            return null;
        }

        return strlen($number) <= 4
            ? $number
            : str_repeat('•', strlen($number) - 4) . substr($number, -4);
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
                'payslip_no' => $this->payslipNo($p, $period),
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
                'period' => $this->periodData($period),
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

        $payroll->load([
            'employee:id,emp_id,firstname,lastname,department_id',
            'employee.department',
            'payrollGroup:id,group_name,frequency',
            'salaryType:id,type_name',
            'contributions.contributionType:id,contribution_code,contribution_name',
        ]);

        $company = Company::query()->find($period->company_id);
        $rateType = $payroll->breakdown['rate_type'] ?? 'monthly';

        // Earnings
        $basicNote = match ($rateType) {
            'daily' => "{$payroll->days_worked} day(s) × ₱" . number_format((float) $payroll->daily_rate, 2),
            'hourly' => $this->minutesLabel((int) $payroll->worked_minutes) . ' worked',
            default => 'Semi-monthly rate',
        };

        $earnings = array_values(array_filter([
            ['label' => 'Basic Pay', 'note' => $basicNote, 'amount' => (float) $payroll->basic_pay],
            (float) $payroll->allowance_pay > 0
                ? ['label' => 'Allowance', 'note' => null, 'amount' => (float) $payroll->allowance_pay]
                : null,
            (float) $payroll->overtime_pay > 0
                ? ['label' => 'Overtime', 'note' => $this->minutesLabel((int) $payroll->overtime_minutes), 'amount' => (float) $payroll->overtime_pay]
                : null,
        ]));

        // Less: time not worked
        $timeDeductions = array_values(array_filter([
            (float) $payroll->late_deduction > 0
                ? ['label' => 'Late', 'note' => $this->minutesLabel((int) $payroll->late_minutes), 'amount' => (float) $payroll->late_deduction]
                : null,
            (float) $payroll->undertime_deduction > 0
                ? ['label' => 'Undertime', 'note' => $this->minutesLabel((int) $payroll->undertime_minutes), 'amount' => (float) $payroll->undertime_deduction]
                : null,
            (float) $payroll->unpaid_leave_deduction > 0
                ? ['label' => 'Unpaid Leave', 'note' => (float) $payroll->unpaid_leave_days . ' day(s)', 'amount' => (float) $payroll->unpaid_leave_deduction]
                : null,
        ]));

        // Deductions from gross pay
        $deductions = [];

        foreach ($payroll->contributions as $c) {
            $deductions[] = [
                'label' => $c->contributionType?->contribution_name ?? 'Contribution',
                'note' => null,
                'amount' => (float) $c->employee_share,
            ];
        }

        $deductions[] = [
            'label' => 'Withholding Tax',
            'note' => null,
            'amount' => (float) $payroll->withholding_tax,
        ];

        foreach ($payroll->breakdown['loans'] ?? [] as $loan) {
            $deductions[] = [
                'label' => $loan['loan_type'] ?? 'Loan',
                'note' => trim(($loan['reference'] ?? '') . ' · balance ₱' . number_format((float) ($loan['balance_after'] ?? 0), 2), ' ·'),
                'amount' => (float) $loan['amount'],
            ];
        }

        // Paid by the company (for information)
        $employerShares = $payroll->contributions->map(fn ($c) => [
            'label' => $c->contributionType?->contribution_name ?? 'Contribution',
            'note' => (float) $c->employer_ec_amount > 0
                ? 'incl. EC ₱' . number_format((float) $c->employer_ec_amount, 2)
                : null,
            'amount' => round((float) $c->employer_share + (float) $c->employer_ec_amount, 2),
        ])->values();

        return response()->json([
            'success' => true,
            'message' => 'Payslip retrieved successfully.',
            'data' => [
                'payslip_no' => $this->payslipNo($payroll, $period),
                'company' => [
                    'name' => $company?->company_name ?? 'Company',
                    'address' => $company?->address ?? null,
                ],
                'period' => $this->periodData($period),
                'employee' => [
                    'emp_id' => $payroll->employee?->emp_id,
                    'name' => $this->employeeName($payroll->employee),
                    'department' => $payroll->employee?->department?->department_name,
                    'payroll_group' => $payroll->payrollGroup?->group_name,
                    'salary_type' => $payroll->salaryType?->type_name,
                    'basic_salary' => (float) $payroll->basic_salary,
                ],
                'earnings' => $earnings,
                'time_deductions' => $timeDeductions,
                'deductions' => $deductions,
                'employer_shares' => $employerShares,
                'totals' => [
                    'earnings' => round(collect($earnings)->sum('amount'), 2),
                    'time_deductions' => round(collect($timeDeductions)->sum('amount'), 2),
                    'gross_pay' => (float) $payroll->gross_pay,
                    'total_deductions' => (float) $payroll->total_deductions,
                    'net_pay' => (float) $payroll->net_pay,
                ],
                'bank' => [
                    'bank_name' => $payroll->bank_name ?: null,
                    'account_name' => $payroll->account_name ?: null,
                    'account_number' => $this->maskAccount($payroll->account_number),
                ],
                'finalized_at' => $payroll->finalized_at?->format('Y-m-d H:i'),
            ],
        ]);
    }
}