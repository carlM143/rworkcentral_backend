<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WithholdingTaxReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'department_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $months = DB::table('payrolls')
            ->join('payroll_periods', 'payroll_periods.id', '=', 'payrolls.payroll_period_id')
            ->selectRaw("DISTINCT DATE_FORMAT(payroll_periods.cutoff_end, '%Y-%m') AS m")
            ->orderByDesc('m')
            ->pluck('m');

        $month = $validated['month'] ?? $months->first() ?? today()->format('Y-m');
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();

        $all = $this->rows($start);

        $search = mb_strtolower(trim((string) ($validated['search'] ?? '')));
        $rows = $all
            ->when($validated['department_id'] ?? null, fn ($c, $id) => $c->where('department_id', (int) $id))
            ->when($search !== '', fn ($c) => $c->filter(fn ($r) => str_contains(mb_strtolower(
                $r['employee'].' '.$r['emp_id'].' '.($r['tin'] ?? '').' '.$r['department']
            ), $search)))
            ->values();

        $draft = $all->sum('draft_payrolls');

        return response()->json([
            'success' => true,
            'month' => $month,
            'month_label' => $start->format('F Y'),
            'official' => $all->isNotEmpty() && $draft === 0,
            'draft_payrolls' => $draft,
            'data' => $rows->map(fn ($r) => collect($r)->except('draft_payrolls')->all())->values(),
            'totals' => [
                'employees' => $rows->count(),
                'gross' => round($rows->sum('gross'), 2),
                'non_taxable' => round($rows->sum('non_taxable'), 2),
                'taxable' => round($rows->sum('taxable'), 2),
                'tax' => round($rows->sum('tax'), 2),
                'mwe' => $rows->where('mwe', true)->count(),
                'missing_tin' => $rows->whereNull('tin')->where('tax', '>', 0)->count(),
            ],
            'filters' => [
                'months' => $months->map(fn ($m) => ['value' => $m, 'label' => Carbon::createFromFormat('Y-m', $m)->format('F Y')])->values(),
                'departments' => $all->whereNotNull('department_id')->unique('department_id')
                    ->map(fn ($r) => ['id' => $r['department_id'], 'name' => $r['department']])->sortBy('name')->values(),
            ],
        ]);
    }

    private function rows(Carbon $month): Collection
    {
        $payrolls = DB::table('payrolls')
            ->join('payroll_periods', 'payroll_periods.id', '=', 'payrolls.payroll_period_id')
            ->whereBetween('payroll_periods.cutoff_end', [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()])
            ->where('payroll_periods.status', '!=', 'cancelled')
            ->get(['payrolls.employee_id', 'payrolls.status', 'payrolls.gross_pay', 'payrolls.withholding_tax', 'payrolls.breakdown'])
            ->groupBy('employee_id');

        if ($payrolls->isEmpty()) {
            return collect();
        }

        $employees = DB::table('employees')
            ->leftJoin('employments', function ($join) {
                $join->on('employments.employee_id', '=', 'employees.id')
                    ->whereRaw('employments.id = (SELECT MAX(e2.id) FROM employments e2 WHERE e2.employee_id = employees.id)');
            })
            ->leftJoin('departments', 'departments.id', '=', 'employments.department_id')
            ->whereIn('employees.id', $payrolls->keys())
            ->get(['employees.id', 'employees.emp_id', 'employees.firstname', 'employees.middlename', 'employees.lastname', 'employments.department_id', 'departments.department_name'])
            ->keyBy('id');

        $tins = DB::table('government_requirements')
            ->whereIn('employee_id', $payrolls->keys())
            ->get(['employee_id', 'id_name', 'id_number'])
            ->groupBy('employee_id')
            ->map(fn ($items) => $items->first(function ($i) {
                $name = mb_strtolower((string) $i->id_name);

                return str_contains($name, 'tin') || str_contains($name, 'tax');
            })?->id_number);

        return $payrolls->map(function (Collection $items, $employeeId) use ($employees, $tins) {
            $e = $employees->get($employeeId);
            $gross = 0.0;
            $taxable = 0.0;
            $tax = 0.0;
            $mwe = false;

            foreach ($items as $p) {
                $detail = json_decode($p->breakdown ?? '{}', true)['tax'] ?? null;
                $gross += (float) $p->gross_pay;
                $tax += (float) $p->withholding_tax;
                $taxable += (float) ($detail['taxable_compensation'] ?? 0);
                $mwe = $mwe || (bool) ($detail['minimum_wage_earner'] ?? false);
            }

            $tin = trim((string) ($tins->get($employeeId) ?? ''));

            return [
                'employee_id' => (int) $employeeId,
                'employee' => $e ? trim($e->lastname.', '.$e->firstname.($e->middlename ? ' '.mb_substr($e->middlename, 0, 1).'.' : '')) : 'Unknown',
                'emp_id' => (string) ($e->emp_id ?? ''),
                'department_id' => $e?->department_id ? (int) $e->department_id : null,
                'department' => $e->department_name ?? '—',
                'tin' => $tin !== '' ? $tin : null,
                'mwe' => $mwe,
                'gross' => round($gross, 2),
                'non_taxable' => round(max(0, $gross - $taxable), 2),
                'taxable' => round($taxable, 2),
                'tax' => round($tax, 2),
                'cutoffs' => $items->count(),
                'draft_payrolls' => $items->where('status', 'draft')->count(),
            ];
        })->sortBy('employee')->values();
    }
}
