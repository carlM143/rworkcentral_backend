<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reports › SSS, Pag-IBIG and PhilHealth (read-only, monthly remittance).
 *
 *   GET /admin/reports/contributions/{agency}?month=YYYY-MM&department_id=&search=
 *       agency = sss | philhealth | pagibig
 *
 * From payroll_contributions (saved when payroll is generated). Both cutoffs
 * of a month are combined by contribution_month. Draft payroll is a preview;
 * the month is official when every payroll in it is finalized.
 */
class ContributionReportController extends Controller
{
    private const AGENCIES = [
        'sss' => ['code' => 'SSS', 'label' => 'SSS', 'id_label' => 'SSS No.', 'basis_label' => 'Monthly Salary Credit', 'id_keywords' => ['sss', 'social security']],
        'philhealth' => ['code' => 'PHIC', 'label' => 'PhilHealth', 'id_label' => 'PhilHealth No.', 'basis_label' => 'Monthly Basic Salary', 'id_keywords' => ['philhealth', 'phic']],
        'pagibig' => ['code' => 'HDMF', 'label' => 'Pag-IBIG', 'id_label' => 'Pag-IBIG MID No.', 'basis_label' => 'Monthly Compensation', 'id_keywords' => ['pag-ibig', 'pagibig', 'hdmf', 'mid']],
    ];

    public function index(Request $request, string $agency): JsonResponse
    {
        abort_unless(isset(self::AGENCIES[$agency]), 404, 'Unknown report.');
        $config = self::AGENCIES[$agency];

        $validated = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'department_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $typeIds = DB::table('contribution_types')->where('contribution_code', $config['code'])->pluck('id');

        $months = DB::table('payroll_contributions')
            ->whereIn('contribution_type_id', $typeIds)
            ->select('contribution_month')
            ->distinct()
            ->orderByDesc('contribution_month')
            ->pluck('contribution_month')
            ->map(fn ($m) => Carbon::parse($m)->format('Y-m'))
            ->unique()
            ->values();

        $month = $validated['month'] ?? $months->first() ?? today()->format('Y-m');
        $monthStart = Carbon::createFromFormat('Y-m', $month)->startOfMonth();

        $all = $this->rows($typeIds, $monthStart, $config['id_keywords']);

        $search = mb_strtolower(trim((string) ($validated['search'] ?? '')));
        $rows = $all
            ->when($validated['department_id'] ?? null, fn ($c, $id) => $c->where('department_id', (int) $id))
            ->when($search !== '', fn ($c) => $c->filter(fn ($r) => str_contains(mb_strtolower(
                $r['employee'].' '.$r['emp_id'].' '.($r['id_number'] ?? '').' '.$r['department']
            ), $search)))
            ->values();

        // Payroll behind this month: official when all are finalized.
        $payrollIds = $all->flatMap(fn ($r) => $r['payroll_ids'])->unique();
        $payrollStatus = DB::table('payrolls')->whereIn('id', $payrollIds)
            ->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $periods = DB::table('payroll_periods')
            ->whereIn('id', DB::table('payrolls')->whereIn('id', $payrollIds)->select('payroll_period_id'))
            ->orderBy('cutoff_start')
            ->get(['id', 'period_name', 'status']);

        $draft = (int) ($payrollStatus['draft'] ?? 0);

        return response()->json([
            'success' => true,
            'message' => "{$config['label']} report retrieved.",
            'agency' => [
                'key' => $agency,
                'label' => $config['label'],
                'id_label' => $config['id_label'],
                'basis_label' => $config['basis_label'],
                'has_ec' => $agency === 'sss',
            ],
            'month' => $month,
            'month_label' => $monthStart->format('F Y'),
            'official' => $payrollIds->isNotEmpty() && $draft === 0,
            'draft_payrolls' => $draft,
            'periods' => $periods,
            'data' => $rows->map(fn ($r) => collect($r)->except('payroll_ids')->all())->values(),
            'totals' => [
                'employees' => $rows->count(),
                'employee_share' => round($rows->sum('employee_share'), 2),
                'employer_share' => round($rows->sum('employer_share'), 2),
                'ec' => round($rows->sum('ec'), 2),
                'total' => round($rows->sum('total'), 2),
                'missing_id' => $rows->whereNull('id_number')->count(),
            ],
            'filters' => [
                'months' => $months->map(fn ($m) => ['value' => $m, 'label' => Carbon::createFromFormat('Y-m', $m)->format('F Y')])->values(),
                'departments' => $all->whereNotNull('department_id')->unique('department_id')
                    ->map(fn ($r) => ['id' => $r['department_id'], 'name' => $r['department']])->sortBy('name')->values(),
            ],
        ]);
    }

    /**
     * One row per employee for the month (both cutoffs combined).
     *
     * @param  Collection<int, int>  $typeIds
     * @param  array<int, string>  $idKeywords
     */
    private function rows(Collection $typeIds, Carbon $month, array $idKeywords): Collection
    {
        $lines = DB::table('payroll_contributions')
            ->whereIn('contribution_type_id', $typeIds)
            ->whereBetween('contribution_month', [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()])
            ->get(['employee_id', 'payroll_id', 'salary_basis_amount', 'employee_share', 'employer_share', 'employer_ec_amount'])
            ->groupBy('employee_id');

        if ($lines->isEmpty()) {
            return collect();
        }

        $employees = DB::table('employees')
            ->leftJoin('employments', function ($join) {
                $join->on('employments.employee_id', '=', 'employees.id')
                    ->whereRaw('employments.id = (SELECT MAX(e2.id) FROM employments e2 WHERE e2.employee_id = employees.id)');
            })
            ->leftJoin('departments', 'departments.id', '=', 'employments.department_id')
            ->whereIn('employees.id', $lines->keys())
            ->get(['employees.id', 'employees.emp_id', 'employees.firstname', 'employees.middlename', 'employees.lastname', 'employments.department_id', 'departments.department_name'])
            ->keyBy('id');

        // Government ID number by keyword in id_name (e.g. "SSS", "PhilHealth", "Pag-IBIG").
        $ids = DB::table('government_requirements')
            ->whereIn('employee_id', $lines->keys())
            ->get(['employee_id', 'id_name', 'id_number'])
            ->groupBy('employee_id')
            ->map(fn ($items) => $items->first(function ($item) use ($idKeywords) {
                $name = mb_strtolower((string) $item->id_name);
                foreach ($idKeywords as $keyword) {
                    if (str_contains($name, $keyword)) {
                        return true;
                    }
                }

                return false;
            })?->id_number);

        return $lines->map(function (Collection $items, $employeeId) use ($employees, $ids) {
            $e = $employees->get($employeeId);
            $ee = round($items->sum(fn ($i) => (float) $i->employee_share), 2);
            $er = round($items->sum(fn ($i) => (float) $i->employer_share), 2);
            $ec = round($items->sum(fn ($i) => (float) $i->employer_ec_amount), 2);
            $idNumber = trim((string) ($ids->get($employeeId) ?? ''));

            return [
                'employee_id' => (int) $employeeId,
                'employee' => $e ? trim($e->lastname.', '.$e->firstname.($e->middlename ? ' '.mb_substr($e->middlename, 0, 1).'.' : '')) : 'Unknown',
                'emp_id' => (string) ($e->emp_id ?? ''),
                'department_id' => $e?->department_id ? (int) $e->department_id : null,
                'department' => $e->department_name ?? '—',
                'id_number' => $idNumber !== '' ? $idNumber : null,
                // Monthly basis is the same on both cutoffs; take the highest.
                'salary_basis' => round((float) $items->max('salary_basis_amount'), 2),
                'employee_share' => $ee,
                'employer_share' => $er,
                'ec' => $ec,
                'total' => round($ee + $er + $ec, 2),
                'cutoffs' => $items->pluck('payroll_id')->unique()->count(),
                'payroll_ids' => $items->pluck('payroll_id')->unique()->values()->all(),
            ];
        })->sortBy('employee')->values();
    }
}