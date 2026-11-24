<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reports › 13th Month Pay (read-only).
 *
 *   GET /admin/reports/thirteenth-month?year=&department_id=&search=&include_draft=1
 *
 * PD 851: 13th month pay = total basic salary EARNED in the calendar year ÷ 12.
 * Earned basic salary = basic pay − late, undertime, unpaid leave and unpaid
 * holiday deductions (overtime, holiday premiums and allowances are excluded).
 *
 * The year is taken from each pay period's cutoff end. Draft payroll can be
 * included as a preview; finalized payroll is official.
 */
class ThirteenthMonthReportController extends Controller
{
    /** Tax-exempt ceiling for 13th month pay and other benefits (TRAIN law). */
    private const TAX_EXEMPT_CEILING = 90000;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'department_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'include_draft' => ['nullable', 'boolean'],
        ]);

        $years = DB::table('payrolls')
            ->join('payroll_periods', 'payroll_periods.id', '=', 'payrolls.payroll_period_id')
            ->selectRaw('DISTINCT YEAR(payroll_periods.cutoff_end) AS y')
            ->orderByDesc('y')
            ->pluck('y')
            ->map(fn ($y) => (int) $y)
            ->values();

        $year = (int) ($validated['year'] ?? $years->first() ?? today()->year);
        $includeDraft = (bool) ($validated['include_draft'] ?? true);

        $all = $this->rows($year, $includeDraft);

        $search = mb_strtolower(trim((string) ($validated['search'] ?? '')));
        $rows = $all
            ->when($validated['department_id'] ?? null, fn ($c, $id) => $c->where('department_id', (int) $id))
            ->when($search !== '', fn ($c) => $c->filter(fn ($r) => str_contains(mb_strtolower(
                $r['employee'].' '.$r['emp_id'].' '.$r['department'].' '.$r['position']
            ), $search)))
            ->values();

        $draft = $all->sum('draft_payrolls');

        return response()->json([
            'success' => true,
            'message' => '13th month pay retrieved.',
            'year' => $year,
            'include_draft' => $includeDraft,
            'official' => $all->isNotEmpty() && $draft === 0,
            'draft_payrolls' => $draft,
            'deadline' => Carbon::create($year, 12, 24)->toDateString(),
            'covered_until' => $all->max('last_cutoff'),
            'data' => $rows,
            'totals' => [
                'employees' => $rows->count(),
                'basic_pay' => round($rows->sum('basic_pay'), 2),
                'deductions' => round($rows->sum('deductions'), 2),
                'earned_basic' => round($rows->sum('earned_basic'), 2),
                'thirteenth_month' => round($rows->sum('thirteenth_month'), 2),
                'taxable_excess' => round($rows->sum('taxable_excess'), 2),
            ],
            'filters' => [
                'years' => $years->isEmpty() ? [today()->year] : $years,
                'departments' => $all->whereNotNull('department_id')->unique('department_id')
                    ->map(fn ($r) => ['id' => $r['department_id'], 'name' => $r['department']])->sortBy('name')->values(),
            ],
        ]);
    }

    private function rows(int $year, bool $includeDraft): Collection
    {
        $lines = DB::table('payrolls')
            ->join('payroll_periods', 'payroll_periods.id', '=', 'payrolls.payroll_period_id')
            ->whereYear('payroll_periods.cutoff_end', $year)
            ->where('payroll_periods.status', '!=', 'cancelled')
            ->when(! $includeDraft, fn ($q) => $q->where('payrolls.status', 'finalized'))
            ->get([
                'payrolls.employee_id',
                'payrolls.status',
                'payrolls.basic_pay',
                'payrolls.late_deduction',
                'payrolls.undertime_deduction',
                'payrolls.unpaid_leave_deduction',
                'payrolls.unpaid_holiday_deduction',
                'payroll_periods.cutoff_end',
            ])
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
            ->leftJoin('positions', 'positions.id', '=', 'employments.position_id')
            ->whereIn('employees.id', $lines->keys())
            ->get([
                'employees.id', 'employees.emp_id', 'employees.firstname', 'employees.lastname', 'employees.status',
                'employments.department_id', 'departments.department_name', 'positions.position',
            ])
            ->keyBy('id');

        $n = fn ($v) => (float) ($v ?? 0);

        return $lines->map(function (Collection $items, $employeeId) use ($employees, $n) {
            $e = $employees->get($employeeId);
            $basic = round($items->sum(fn ($i) => $n($i->basic_pay)), 2);
            $deductions = round($items->sum(fn ($i) => $n($i->late_deduction) + $n($i->undertime_deduction)
                + $n($i->unpaid_leave_deduction) + $n($i->unpaid_holiday_deduction)), 2);
            $earned = max(0, round($basic - $deductions, 2));
            $thirteenth = round($earned / 12, 2);

            return [
                'employee_id' => (int) $employeeId,
                'employee' => $e ? trim($e->firstname.' '.$e->lastname) : 'Unknown',
                'emp_id' => (string) ($e->emp_id ?? ''),
                'active' => (bool) ($e->status ?? false),
                'department_id' => $e?->department_id ? (int) $e->department_id : null,
                'department' => $e->department_name ?? '—',
                'position' => $e->position ?? '—',
                'payrolls' => $items->count(),
                'months' => $items->map(fn ($i) => substr((string) $i->cutoff_end, 0, 7))->unique()->count(),
                'draft_payrolls' => $items->where('status', 'draft')->count(),
                'last_cutoff' => (string) $items->max('cutoff_end'),
                'basic_pay' => $basic,
                'deductions' => $deductions,
                'earned_basic' => $earned,
                'thirteenth_month' => $thirteenth,
                // Simplified: other benefits are not tracked yet, so only the
                // 13th month pay is checked against the ceiling.
                'taxable_excess' => round(max(0, $thirteenth - self::TAX_EXEMPT_CEILING), 2),
            ];
        })->sortBy('employee')->values();
    }
}