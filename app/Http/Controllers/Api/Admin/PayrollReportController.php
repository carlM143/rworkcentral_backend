<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reports › Payroll Reports (read-only).
 *
 *   GET /admin/reports/payroll?payroll_period_id=&department_id=&employment_type_id=&status=&search=
 *
 * The payroll register of a pay period from generated payroll (payrolls) and
 * its statutory contributions (payroll_contributions: SSS, PHIC, HDMF).
 * Draft payroll is shown as a preview; finalized payroll is official.
 */
class PayrollReportController extends Controller
{
    private const CONTRIBUTIONS = ['SSS' => 'sss', 'PHIC' => 'philhealth', 'HDMF' => 'pagibig'];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payroll_period_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'employment_type_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:draft,finalized'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $periods = $this->periods();
        $periodId = (int) ($validated['payroll_period_id'] ?? 0)
            ?: (int) ($periods->firstWhere('payrolls', '>', 0)['id'] ?? $periods->first()['id'] ?? 0);
        $period = $periods->firstWhere('id', $periodId);

        $all = $period ? $this->rows($periodId) : collect();

        $search = mb_strtolower(trim((string) ($validated['search'] ?? '')));
        $rows = $all
            ->when($validated['department_id'] ?? null, fn ($c, $id) => $c->where('department_id', (int) $id))
            ->when($validated['employment_type_id'] ?? null, fn ($c, $id) => $c->where('employment_type_id', (int) $id))
            ->when($validated['status'] ?? null, fn ($c, $s) => $c->where('status', $s))
            ->when($search !== '', fn ($c) => $c->filter(fn ($r) => str_contains(mb_strtolower(
                $r['employee'].' '.$r['emp_id'].' '.$r['payroll_no'].' '.$r['position'].' '.$r['department']
            ), $search)))
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Payroll report retrieved.',
            'period' => $period,
            'data' => $rows,
            'totals' => $this->totals($rows),
            'departments' => $this->byDepartment($rows),
            'counts' => [
                'all' => $all->count(),
                'draft' => $all->where('status', 'draft')->count(),
                'finalized' => $all->where('status', 'finalized')->count(),
            ],
            'filters' => [
                'periods' => $periods->values(),
                'departments' => $all->whereNotNull('department_id')->unique('department_id')
                    ->map(fn ($r) => ['id' => $r['department_id'], 'name' => $r['department']])->sortBy('name')->values(),
                'employment_types' => $all->whereNotNull('employment_type_id')->unique('employment_type_id')
                    ->map(fn ($r) => ['id' => $r['employment_type_id'], 'name' => $r['employment_type']])->sortBy('name')->values(),
            ],
        ]);
    }

    /**
     * Pay periods (newest first) with their payroll count.
     */
    private function periods(): Collection
    {
        $counts = DB::table('payrolls')
            ->selectRaw('payroll_period_id, COUNT(*) AS total, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS finalized_total', ['finalized'])
            ->groupBy('payroll_period_id')
            ->get()
            ->keyBy('payroll_period_id');

        return DB::table('payroll_periods')
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('pay_period_start')
            ->orderByDesc('id')
            ->get(['id', 'period_name', 'pay_period_start', 'pay_period_end', 'cutoff_start', 'cutoff_end', 'payment_date', 'status'])
            ->map(fn ($p) => [
                'id' => (int) $p->id,
                'name' => $p->period_name,
                'pay_period_start' => $p->pay_period_start,
                'pay_period_end' => $p->pay_period_end,
                'cutoff_start' => $p->cutoff_start,
                'cutoff_end' => $p->cutoff_end,
                'payment_date' => $p->payment_date,
                'status' => $p->status,
                'payrolls' => (int) ($counts[$p->id]->total ?? 0),
                'finalized' => (int) ($counts[$p->id]->finalized_total ?? 0),
                'official' => $p->status === 'closed',
            ]);
    }

    /**
     * Payroll register rows of a period.
     */
    private function rows(int $periodId): Collection
    {
        $payrolls = DB::table('payrolls')
            ->join('employees', 'employees.id', '=', 'payrolls.employee_id')
            ->leftJoin('employments', function ($join) {
                $join->on('employments.employee_id', '=', 'employees.id')
                    ->whereRaw('employments.id = (SELECT MAX(e2.id) FROM employments e2 WHERE e2.employee_id = employees.id)');
            })
            ->leftJoin('departments', 'departments.id', '=', 'employments.department_id')
            ->leftJoin('positions', 'positions.id', '=', 'employments.position_id')
            ->leftJoin('employment_types', 'employment_types.id', '=', 'employments.employment_type_id')
            ->leftJoin('payroll_groups', 'payroll_groups.id', '=', 'payrolls.payroll_group_id')
            ->leftJoin('salary_types', 'salary_types.id', '=', 'payrolls.salary_type_id')
            ->where('payrolls.payroll_period_id', $periodId)
            ->orderBy('departments.department_name')
            ->orderBy('employees.lastname')
            ->orderBy('employees.firstname')
            ->get([
                'payrolls.*',
                'employees.emp_id',
                'employees.firstname',
                'employees.lastname',
                'employments.department_id',
                'departments.department_name',
                'positions.position',
                'employments.employment_type_id',
                'employment_types.name AS employment_type_name',
                'payroll_groups.group_name AS payroll_group_name',
                'salary_types.type_name AS salary_type_name',
            ]);

        $contributions = DB::table('payroll_contributions')
            ->join('contribution_types', 'contribution_types.id', '=', 'payroll_contributions.contribution_type_id')
            ->whereIn('payroll_contributions.payroll_id', $payrolls->pluck('id'))
            ->get([
                'payroll_contributions.payroll_id',
                'contribution_types.contribution_code',
                'payroll_contributions.employee_share',
                'payroll_contributions.employer_share',
                'payroll_contributions.employer_ec_amount',
            ])
            ->groupBy('payroll_id');

        $n = fn ($v) => round((float) ($v ?? 0), 2);

        return $payrolls->map(function ($p) use ($contributions, $n) {
            $shares = ['sss' => 0.0, 'philhealth' => 0.0, 'pagibig' => 0.0, 'other' => 0.0];
            $employer = 0.0;

            foreach ($contributions->get($p->id, collect()) as $c) {
                $key = self::CONTRIBUTIONS[strtoupper((string) $c->contribution_code)] ?? 'other';
                $shares[$key] += (float) $c->employee_share;
                $employer += (float) $c->employer_share + (float) $c->employer_ec_amount;
            }

            $attendance = $n($p->late_deduction) + $n($p->undertime_deduction)
                + $n($p->unpaid_leave_deduction) + $n($p->unpaid_holiday_deduction);

            return [
                'id' => (int) $p->id,
                'payroll_no' => 'PAY-'.str_pad((string) $p->payroll_period_id, 3, '0', STR_PAD_LEFT).'-'.str_pad((string) $p->id, 5, '0', STR_PAD_LEFT),
                'employee_id' => (int) $p->employee_id,
                'employee' => trim($p->firstname.' '.$p->lastname),
                'emp_id' => (string) $p->emp_id,
                'department_id' => $p->department_id ? (int) $p->department_id : null,
                'department' => $p->department_name ?? '—',
                'position' => $p->position ?? '—',
                'employment_type_id' => $p->employment_type_id ? (int) $p->employment_type_id : null,
                'employment_type' => $p->employment_type_name ?? '—',
                'payroll_group' => $p->payroll_group_name,
                'salary_type' => $p->salary_type_name,
                'basic_salary' => $n($p->basic_salary),
                'days_worked' => (int) ($p->days_worked ?? 0),
                // Earnings
                'basic_pay' => $n($p->basic_pay),
                'overtime_pay' => $n($p->overtime_pay),
                'holiday_pay' => $n($p->holiday_pay),
                'allowance_pay' => $n($p->allowance_pay),
                'gross_pay' => $n($p->gross_pay),
                // Deductions
                'late_deduction' => $n($p->late_deduction),
                'undertime_deduction' => $n($p->undertime_deduction),
                'unpaid_leave_deduction' => $n($p->unpaid_leave_deduction),
                'unpaid_holiday_deduction' => $n($p->unpaid_holiday_deduction),
                'attendance_deductions' => round($attendance, 2),
                'sss' => round($shares['sss'], 2),
                'philhealth' => round($shares['philhealth'], 2),
                'pagibig' => round($shares['pagibig'], 2),
                'other_contributions' => round($shares['other'], 2),
                'contributions_total' => $n($p->contributions_total),
                'withholding_tax' => $n($p->withholding_tax),
                'loan_deductions' => $n($p->loan_deductions_total),
                'total_deductions' => $n($p->total_deductions),
                'net_pay' => $n($p->net_pay),
                'employer_contributions' => round($employer, 2),
                // Bank
                'bank_name' => $p->bank_name,
                'account_name' => $p->account_name,
                'account_number' => $p->account_number,
                'status' => $p->status,
                'generated_at' => $p->generated_at ? Carbon::parse($p->generated_at)->toIso8601String() : null,
                'finalized_at' => $p->finalized_at ? Carbon::parse($p->finalized_at)->toIso8601String() : null,
            ];
        })->values();
    }

    private function totals(Collection $rows): array
    {
        $keys = [
            'basic_pay', 'overtime_pay', 'holiday_pay', 'allowance_pay', 'gross_pay',
            'attendance_deductions', 'sss', 'philhealth', 'pagibig', 'other_contributions',
            'contributions_total', 'withholding_tax', 'loan_deductions', 'total_deductions',
            'net_pay', 'employer_contributions',
        ];

        return ['employees' => $rows->count()]
            + collect($keys)->mapWithKeys(fn ($k) => [$k => round($rows->sum($k), 2)])->all();
    }

    private function byDepartment(Collection $rows): Collection
    {
        return $rows->groupBy('department')
            ->map(fn (Collection $group, string $name) => [
                'department' => $name,
                'employees' => $group->count(),
                'gross_pay' => round($group->sum('gross_pay'), 2),
                'total_deductions' => round($group->sum('total_deductions'), 2),
                'net_pay' => round($group->sum('net_pay'), 2),
                'employer_contributions' => round($group->sum('employer_contributions'), 2),
            ])
            ->sortBy('department')
            ->values();
    }
}