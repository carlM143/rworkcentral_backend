<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Department;
use App\Models\Payroll;
use App\Models\PayrollPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeePayrollController extends Controller
{
    private function companyId(Request $request): int
    {
        abort_unless($request->user(), 401, 'Unauthenticated.');

        if ($request->user()->company_id) {
            return (int) $request->user()->company_id;
        }

        return (int) Company::query()->sole()->id;
    }

    private function payslipNo(Payroll $payroll, PayrollPeriod $period): string
    {
        $date = ($period->pay_period_start ?? $period->cutoff_end)->format('Ym');

        return sprintf('PS-%s-%d-%05d', $date, (int) $period->cutoff_number, $payroll->id);
    }

    // GET /api/admin/employee-payrolls
    // ?payroll_period_id=&department_id=&status=draft|finalized&search=&page=&per_page=
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payroll_period_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:draft,finalized'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $companyId = $this->companyId($request);

        $periods = PayrollPeriod::query()
            ->where('company_id', $companyId)
            ->orderByDesc('pay_period_start')
            ->get()
            ->keyBy('id');

        $query = Payroll::query()
            ->whereIn('payroll_period_id', $periods->keys())
            ->when($data['payroll_period_id'] ?? null, fn (Builder $q, $id) => $q->where('payroll_period_id', $id))
            ->when($data['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($data['department_id'] ?? null, fn (Builder $q, $id) => $q->whereHas(
                'employee',
                fn (Builder $e) => $e->where('department_id', $id)
            ))
            ->when($data['search'] ?? null, function (Builder $q, string $term) {
                $q->whereHas('employee', fn (Builder $e) => $e->where(fn (Builder $w) => $w
                    ->where('emp_id', 'like', "%{$term}%")
                    ->orWhere('firstname', 'like', "%{$term}%")
                    ->orWhere('lastname', 'like', "%{$term}%")));
            });

        // Totals for the summary cards (same filters, one query)
        $totals = (clone $query)
            ->selectRaw('COUNT(*) as records, COALESCE(SUM(gross_pay),0) as gross, COALESCE(SUM(total_deductions),0) as deductions, COALESCE(SUM(net_pay),0) as net')
            ->first();

        $page = $query
            ->with([
                'employee:id,emp_id,firstname,lastname,department_id',
                'employee.department',
                'payrollGroup:id,group_name',
            ])
            ->orderByDesc('payroll_period_id')
            ->orderBy('id')
            ->paginate($data['per_page'] ?? 10);

        $rows = collect($page->items())->map(function (Payroll $p) use ($periods) {
            $period = $periods->get($p->payroll_period_id);

            return [
                'payroll_id' => $p->id,
                'payslip_no' => $period ? $this->payslipNo($p, $period) : null,
                'employee' => [
                    'emp_id' => $p->employee?->emp_id,
                    'name' => trim("{$p->employee?->firstname} {$p->employee?->lastname}"),
                    'department' => $p->employee?->department?->department_name,
                ],
                'payroll_group' => $p->payrollGroup?->group_name,
                'period' => $period ? [
                    'id' => $period->id,
                    'period_name' => $period->period_name,
                    'pay_period_start' => $period->pay_period_start?->format('Y-m-d'),
                    'pay_period_end' => $period->pay_period_end?->format('Y-m-d'),
                    'payment_date' => $period->payment_date?->format('Y-m-d'),
                ] : null,
                'basic_pay' => (float) $p->basic_pay,
                'overtime_pay' => (float) $p->overtime_pay,
                'allowance_pay' => (float) $p->allowance_pay,
                'gross_pay' => (float) $p->gross_pay,
                'total_deductions' => (float) $p->total_deductions,
                'net_pay' => (float) $p->net_pay,
                'status' => $p->status,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Employee payroll records retrieved successfully.',
            'data' => [
                'rows' => $rows,
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                    'from' => $page->firstItem() ?? 0,
                    'to' => $page->lastItem() ?? 0,
                ],
                'totals' => [
                    'records' => (int) ($totals->records ?? 0),
                    'gross_pay' => round((float) ($totals->gross ?? 0), 2),
                    'total_deductions' => round((float) ($totals->deductions ?? 0), 2),
                    'net_pay' => round((float) ($totals->net ?? 0), 2),
                ],
                'filters' => [
                    'periods' => $periods->values()->map(fn (PayrollPeriod $period) => [
                        'id' => $period->id,
                        'period_name' => $period->period_name ?? "Payroll Period #{$period->id}",
                        'status' => $period->status,
                    ]),
                    'departments' => Department::query()
                        ->orderBy('department_name')
                        ->get(['id', 'department_name'])
                        ->map(fn ($d) => ['id' => $d->id, 'name' => $d->department_name]),
                ],
            ],
        ]);
    }
}