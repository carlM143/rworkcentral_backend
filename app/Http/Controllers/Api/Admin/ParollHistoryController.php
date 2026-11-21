<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollGroup;
use App\Models\PayrollPeriod;
use App\Services\PayslipService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Payroll History: payroll periods that were generated/finalized, with their totals,
 * a breakdown per period, and one employee's payroll history.
 */
class ParollHistoryController extends Controller
{
    public function __construct(private PayslipService $payslips) {}

    // GET /api/admin/payroll-history?year=2026&status=closed|processing&per_page=
    public function index(Request $request): JsonResponse
    {
        $this->authorizeHistory($request);

        $filters = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'status' => ['nullable', Rule::in(['processing', 'closed'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $companyId = $this->companyId($request);

        // Only periods that have payroll records (generated at least once)
        $periods = PayrollPeriod::query()
            ->where('company_id', $companyId)
            ->whereHas('payrolls')
            ->when($filters['year'] ?? null, fn (Builder $q, $year) => $q->whereYear('pay_period_start', $year))
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->orderByDesc('cutoff_end')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20);

        $totals = $this->totalsByPeriod(collect($periods->items())->pluck('id')->all());

        $rows = collect($periods->items())->map(fn (PayrollPeriod $period) => [
            'period' => $this->payslips->periodData($period),
            'totals' => $totals[$period->id] ?? $this->emptyTotals(),
        ]);

        // Totals for the whole filtered range (for the summary cards)
        $overall = Payroll::query()
            ->whereHas('payrollPeriod', fn (Builder $q) => $q
                ->where('company_id', $companyId)
                ->when($filters['year'] ?? null, fn (Builder $p, $year) => $p->whereYear('pay_period_start', $year))
                ->when($filters['status'] ?? null, fn (Builder $p, $status) => $p->where('status', $status)))
            ->selectRaw($this->totalsSelect())
            ->first();

        $years = PayrollPeriod::query()
            ->where('company_id', $companyId)
            ->whereHas('payrolls')
            ->pluck('pay_period_start')
            ->map(fn ($date) => $date?->year)
            ->filter()
            ->unique()
            ->sortDesc()
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Payroll history retrieved successfully.',
            'data' => [
                'periods' => $rows,
                'meta' => [
                    'current_page' => $periods->currentPage(),
                    'last_page' => $periods->lastPage(),
                    'per_page' => $periods->perPage(),
                    'total' => $periods->total(),
                ],
                'summary' => $this->formatTotals($overall),
                'years' => $years,
            ],
        ]);
    }

    // GET /api/admin/payroll-history/{payrollPeriod}?payroll_group_id=&search=&status=draft|finalized
    public function show(Request $request, PayrollPeriod $payrollPeriod): JsonResponse
    {
        $this->authorizeHistory($request);
        abort_unless((int) $payrollPeriod->company_id === $this->companyId($request), 404, 'Payroll period not found.');

        $filters = $request->validate([
            'payroll_group_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['draft', 'finalized'])],
        ]);

        $base = Payroll::query()->where('payroll_period_id', $payrollPeriod->id);

        // Totals per payroll group
        $groupTotals = (clone $base)
            ->selectRaw('payroll_group_id, ' . $this->totalsSelect())
            ->groupBy('payroll_group_id')
            ->get()
            ->keyBy('payroll_group_id');

        $groupNames = PayrollGroup::query()
            ->whereIn('id', $groupTotals->keys())
            ->pluck('group_name', 'id');

        $byGroup = $groupTotals->map(fn ($row, $groupId) => [
            'payroll_group_id' => (int) $groupId,
            'group_name' => $groupNames[$groupId] ?? 'No group',
            'totals' => $this->formatTotals($row),
        ])->values();

        // Employees in this period
        $employees = (clone $base)
            ->with([
                'employee:id,emp_id,firstname,lastname,department_id',
                'employee.department:id,department_name',
                'payrollGroup:id,group_name',
            ])
            ->when($filters['payroll_group_id'] ?? null, fn (Builder $q, $id) => $q->where('payroll_group_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, function (Builder $q, string $term) {
                $like = '%' . trim($term) . '%';

                $q->whereHas('employee', fn (Builder $e) => $e->where(fn (Builder $w) => $w
                    ->where('emp_id', 'like', $like)
                    ->orWhere('firstname', 'like', $like)
                    ->orWhere('lastname', 'like', $like)));
            })
            ->get()
            ->sortBy(fn (Payroll $p) => "{$p->employee?->lastname} {$p->employee?->firstname}")
            ->values()
            ->map(fn (Payroll $p) => $this->formatPayrollRow($p, $payrollPeriod));

        return response()->json([
            'success' => true,
            'message' => 'Payroll period history retrieved successfully.',
            'data' => [
                'period' => $this->payslips->periodData($payrollPeriod),
                'totals' => $this->formatTotals((clone $base)->selectRaw($this->totalsSelect())->first()),
                'by_group' => $byGroup,
                'employees' => $employees,
            ],
        ]);
    }

    // GET /api/admin/payroll-history/employees/{employee}?year=2026
    public function employee(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeHistory($request);

        $filters = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        $companyId = $this->companyId($request);

        $payrolls = Payroll::query()
            ->where('employee_id', $employee->id)
            ->whereHas('payrollPeriod', fn (Builder $q) => $q
                ->where('company_id', $companyId)
                ->when($filters['year'] ?? null, fn (Builder $p, $year) => $p->whereYear('pay_period_start', $year)))
            ->with(['payrollPeriod', 'payrollGroup:id,group_name'])
            ->get()
            ->sortByDesc(fn (Payroll $p) => $p->payrollPeriod?->cutoff_end?->format('Y-m-d') . sprintf('-%010d', $p->id))
            ->values();

        // Year-to-date totals count finalized payrolls only (drafts can still change)
        $finalized = $payrolls->where('status', 'finalized');

        $employee->loadMissing('department:id,department_name');

        return response()->json([
            'success' => true,
            'message' => 'Employee payroll history retrieved successfully.',
            'data' => [
                'employee' => [
                    'id' => $employee->id,
                    'emp_id' => $employee->emp_id,
                    'name' => trim("{$employee->firstname} {$employee->lastname}"),
                    'department' => $employee->department?->department_name,
                ],
                'payrolls' => $payrolls->map(fn (Payroll $p) => [
                    ...$this->formatPayrollRow($p, $p->payrollPeriod),
                    'period' => $this->payslips->periodData($p->payrollPeriod),
                ]),
                'totals' => [
                    'payroll_count' => $finalized->count(),
                    'gross_pay' => round($finalized->sum(fn ($p) => (float) $p->gross_pay), 2),
                    'contributions' => round($finalized->sum(fn ($p) => (float) $p->contributions_total), 2),
                    'withholding_tax' => round($finalized->sum(fn ($p) => (float) $p->withholding_tax), 2),
                    'loan_deductions' => round($finalized->sum(fn ($p) => (float) $p->loan_deductions_total), 2),
                    'total_deductions' => round($finalized->sum(fn ($p) => (float) $p->total_deductions), 2),
                    'net_pay' => round($finalized->sum(fn ($p) => (float) $p->net_pay), 2),
                ],
            ],
        ]);
    }

    private function formatPayrollRow(Payroll $p, PayrollPeriod $period): array
    {
        return [
            'payroll_id' => $p->id,
            'payslip_no' => $this->payslips->payslipNo($p, $period),
            'employee' => [
                'id' => $p->employee_id,
                'emp_id' => $p->employee?->emp_id,
                'name' => trim("{$p->employee?->lastname}, {$p->employee?->firstname}"),
                'department' => $p->employee?->department?->department_name,
            ],
            'payroll_group' => $p->payrollGroup?->group_name,
            'basic_pay' => (float) $p->basic_pay,
            'allowance_pay' => (float) $p->allowance_pay,
            'overtime_pay' => (float) $p->overtime_pay,
            'holiday_pay' => (float) $p->holiday_pay,
            'gross_pay' => (float) $p->gross_pay,
            'contributions_total' => (float) $p->contributions_total,
            'loan_deductions_total' => (float) $p->loan_deductions_total,
            'withholding_tax' => (float) $p->withholding_tax,
            'total_deductions' => (float) $p->total_deductions,
            'net_pay' => (float) $p->net_pay,
            'status' => $p->status,
            'finalized_at' => $p->finalized_at?->toIso8601String(),
            'payslip_sent_at' => $p->payslip_sent_at?->toIso8601String(),
        ];
    }

    /** @param  int[]  $periodIds */
    private function totalsByPeriod(array $periodIds): array
    {
        if (! $periodIds) {
            return [];
        }

        return Payroll::query()
            ->whereIn('payroll_period_id', $periodIds)
            ->selectRaw('payroll_period_id, ' . $this->totalsSelect())
            ->groupBy('payroll_period_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->payroll_period_id => $this->formatTotals($row)])
            ->all();
    }

    private function totalsSelect(): string
    {
        return implode(', ', [
            'COUNT(*) as employees',
            "SUM(CASE WHEN status = 'finalized' THEN 1 ELSE 0 END) as finalized",
            "SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft",
            'SUM(CASE WHEN payslip_sent_at IS NOT NULL THEN 1 ELSE 0 END) as payslips_sent',
            'COALESCE(SUM(gross_pay), 0) as gross_pay',
            'COALESCE(SUM(contributions_total), 0) as contributions_total',
            'COALESCE(SUM(loan_deductions_total), 0) as loan_deductions_total',
            'COALESCE(SUM(withholding_tax), 0) as withholding_tax',
            'COALESCE(SUM(total_deductions), 0) as total_deductions',
            'COALESCE(SUM(net_pay), 0) as net_pay',
            'MAX(finalized_at) as finalized_at',
        ]);
    }

    private function formatTotals($row): array
    {
        if (! $row) {
            return $this->emptyTotals();
        }

        return [
            'employees' => (int) $row->employees,
            'finalized' => (int) $row->finalized,
            'draft' => (int) $row->draft,
            'payslips_sent' => (int) $row->payslips_sent,
            'gross_pay' => round((float) $row->gross_pay, 2),
            'contributions_total' => round((float) $row->contributions_total, 2),
            'loan_deductions_total' => round((float) $row->loan_deductions_total, 2),
            'withholding_tax' => round((float) $row->withholding_tax, 2),
            'total_deductions' => round((float) $row->total_deductions, 2),
            'net_pay' => round((float) $row->net_pay, 2),
            'finalized_at' => $row->finalized_at ? \Illuminate\Support\Carbon::parse($row->finalized_at)->toIso8601String() : null,
        ];
    }

    private function emptyTotals(): array
    {
        return $this->formatTotals((object) [
            'employees' => 0, 'finalized' => 0, 'draft' => 0, 'payslips_sent' => 0,
            'gross_pay' => 0, 'contributions_total' => 0, 'loan_deductions_total' => 0,
            'withholding_tax' => 0, 'total_deductions' => 0, 'net_pay' => 0, 'finalized_at' => null,
        ]);
    }

    private function companyId(Request $request): int
    {
        if ($request->user()->company_id) {
            return (int) $request->user()->company_id;
        }

        return (int) Company::query()->sole()->id;
    }

    /** Payroll › Payroll History: View (Settings › Roles). Administrators have every privilege. */
    private function authorizeHistory(Request $request): void
    {
        abort_unless(
            $request->user()?->hasPrivilege('payroll', 'history.view'),
            403,
            'You are not allowed to view payroll history.'
        );
    }
}
