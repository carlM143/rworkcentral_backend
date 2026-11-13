<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Holiday;
use App\Models\PayrollHolidayPay;
use App\Models\PayrollPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HolidayPayController extends Controller
{
    private const SCENARIO_LABELS = [
        'unworked' => 'Unworked',
        'worked' => 'Worked',
        'worked_overtime' => 'Worked (overtime)',
        'rest_day_worked' => 'Rest day worked',
        'rest_day_overtime' => 'Rest day overtime',
    ];

    private function companyId(Request $request): int
    {
        abort_unless($request->user(), 401, 'Unauthenticated.');

        if ($request->user()->company_id) {
            return (int) $request->user()->company_id;
        }

        return (int) Company::query()->sole()->id;
    }

    // GET /api/admin/holiday-pays
    // ?payroll_period_id=&holiday_id=&department_id=&search=&page=&per_page=
    // Read-only: rows are created by payroll generation.
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payroll_period_id' => ['nullable', 'integer'],
            'holiday_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $companyId = $this->companyId($request);

        $periods = PayrollPeriod::query()
            ->where('company_id', $companyId)
            ->orderByDesc('pay_period_start')
            ->get(['id', 'period_name', 'status', 'pay_period_start'])
            ->keyBy('id');

        $query = PayrollHolidayPay::query()
            ->whereHas('payroll', fn (Builder $q) => $q->whereIn('payroll_period_id', $periods->keys()))
            ->when($data['payroll_period_id'] ?? null, fn (Builder $q, $id) => $q->whereHas(
                'payroll',
                fn (Builder $p) => $p->where('payroll_period_id', $id)
            ))
            ->when($data['holiday_id'] ?? null, fn (Builder $q, $id) => $q->where('holiday_id', $id))
            ->when($data['department_id'] ?? null, fn (Builder $q, $id) => $q->whereHas(
                'employee',
                fn (Builder $e) => $e->where('department_id', $id)
            ))
            ->when($data['search'] ?? null, fn (Builder $q, string $term) => $q->whereHas(
                'employee',
                fn (Builder $e) => $e->where(fn (Builder $w) => $w
                    ->where('emp_id', 'like', "%{$term}%")
                    ->orWhere('firstname', 'like', "%{$term}%")
                    ->orWhere('lastname', 'like', "%{$term}%"))
            ));

        $totals = (clone $query)
            ->selectRaw('COUNT(*) as records, COUNT(DISTINCT employee_id) as employees, COALESCE(SUM(amount), 0) as amount')
            ->first();

        $page = $query
            ->with([
                'employee:id,emp_id,firstname,lastname,department_id',
                'employee.department',
                'holiday:id,holiday_name,holiday_type_id',
                'holiday.holidayType:id,type_code,type_name',
                'payroll:id,payroll_period_id,status',
            ])
            ->orderByDesc('holiday_date')
            ->orderBy('employee_id')
            ->paginate($data['per_page'] ?? 10);

        $rows = collect($page->items())->map(function (PayrollHolidayPay $line) use ($periods) {
            $period = $periods->get($line->payroll?->payroll_period_id);

            return [
                'id' => $line->id,
                'holiday_date' => $line->holiday_date?->format('Y-m-d'),
                'holiday' => [
                    'id' => $line->holiday_id,
                    'name' => $line->holiday?->holiday_name,
                    'type' => $line->holiday?->holidayType?->type_name,
                ],
                'employee' => [
                    'emp_id' => $line->employee?->emp_id,
                    'name' => trim("{$line->employee?->firstname} {$line->employee?->lastname}"),
                    'department' => $line->employee?->department?->department_name,
                ],
                'period' => $period ? [
                    'id' => $period->id,
                    'period_name' => $period->period_name,
                ] : null,
                'payroll_status' => $line->payroll?->status,
                'scenario' => $line->scenario,
                'scenario_label' => self::SCENARIO_LABELS[$line->scenario] ?? $line->scenario,
                'worked_minutes' => $line->worked_minutes,
                'overtime_minutes' => $line->overtime_minutes,
                'daily_rate' => (float) $line->daily_rate,
                'rate_percent' => (float) $line->rate_percent,
                'amount' => (float) $line->amount,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Holiday pay records retrieved successfully.',
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
                    'employees' => (int) ($totals->employees ?? 0),
                    'amount' => round((float) ($totals->amount ?? 0), 2),
                ],
                'filters' => [
                    'periods' => $periods->values()->map(fn ($p) => [
                        'id' => $p->id,
                        'period_name' => $p->period_name ?? "Payroll Period #{$p->id}",
                        'status' => $p->status,
                    ]),
                    'holidays' => Holiday::query()
                        ->where('company_id', $companyId)
                        ->orderBy('holiday_name')
                        ->get(['id', 'holiday_name'])
                        ->map(fn ($h) => ['id' => $h->id, 'name' => $h->holiday_name]),
                ],
            ],
        ]);
    }
}