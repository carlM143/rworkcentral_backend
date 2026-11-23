<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\WageRateService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reports › Wage Compliance.
 *
 *   GET /admin/reports/wage-compliance?as_of=&region=&department_id=&status=&search=
 *
 * Every active employee's daily rate against the minimum wage in force for
 * their work location (region + classification, from published wage orders).
 *
 * Status per employee:
 *   below             below the minimum, no adjustment in progress (non-compliant)
 *   pending           below the minimum, a wage adjustment is pending/approved
 *   distortion        meets the minimum, but the gap is smaller than the last
 *                     increase (possible wage distortion, for HR review)
 *   upcoming          meets the minimum today, below a scheduled wage order
 *   compliant         meets the minimum
 *   no_salary         no salary setup
 *   no_wage_order     no published wage order for the region/classification
 *   no_location       no work location / region
 */
class WageComplianceController extends Controller
{
    public const STATUSES = [
        'below' => 'Below Minimum',
        'pending' => 'Pending Adjustment',
        'distortion' => 'Distortion Review',
        'upcoming' => 'Below Upcoming Minimum',
        'compliant' => 'Compliant',
        'no_salary' => 'No Salary Setup',
        'no_wage_order' => 'No Wage Order',
        'no_location' => 'No Region',
    ];

    public function __construct(private readonly WageRateService $wages)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'as_of' => ['nullable', 'date'],
            'region' => ['nullable', 'string', 'max:20'],
            'department_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::STATUSES))],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $asOf = isset($validated['as_of']) ? Carbon::parse($validated['as_of'])->startOfDay() : today();
        $all = $this->rows($asOf);

        $search = mb_strtolower(trim((string) ($validated['search'] ?? '')));
        $filtered = $all
            ->when($validated['region'] ?? null, fn ($c, $r) => $c->where('region_code', $r))
            ->when($validated['department_id'] ?? null, fn ($c, $d) => $c->where('department_id', (int) $d))
            ->when($validated['status'] ?? null, fn ($c, $s) => $c->where('status', $s))
            ->when($search !== '', fn ($c) => $c->filter(fn ($r) => str_contains(mb_strtolower(
                $r['employee'].' '.$r['emp_id'].' '.$r['department'].' '.$r['position'].' '.$r['location']
            ), $search)))
            ->values();

        $count = fn (string ...$statuses) => $all->whereIn('status', $statuses)->count();

        return response()->json([
            'success' => true,
            'message' => 'Wage compliance retrieved.',
            'data' => $filtered,
            'as_of' => $asOf->toDateString(),
            'stats' => [
                'reviewed' => $all->count(),
                'compliant' => $count('compliant', 'upcoming'),
                'below' => $count('below'),
                'pending' => $count('pending'),
                'distortion' => $count('distortion'),
                'upcoming' => $count('upcoming'),
                'missing_data' => $count('no_salary', 'no_wage_order', 'no_location'),
                'monthly_shortfall' => round($all->whereIn('status', ['below', 'pending'])->sum('monthly_shortfall'), 2),
            ],
            'filters' => [
                'regions' => $all->whereNotNull('region_code')->unique('region_code')
                    ->map(fn ($r) => ['code' => $r['region_code'], 'name' => $r['region_name']])
                    ->sortBy('code')->values(),
                'departments' => $all->whereNotNull('department_id')->unique('department_id')
                    ->map(fn ($r) => ['id' => $r['department_id'], 'name' => $r['department']])
                    ->sortBy('name')->values(),
                'statuses' => collect(self::STATUSES)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            ],
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(Carbon $asOf): Collection
    {
        $employees = DB::table('employees')
            ->join('employments', function ($join) {
                $join->on('employments.employee_id', '=', 'employees.id')
                    ->whereRaw('employments.id = (SELECT MAX(e2.id) FROM employments e2 WHERE e2.employee_id = employees.id)');
            })
            ->leftJoin('locations', 'locations.id', '=', 'employments.location_id')
            ->leftJoin('departments', 'departments.id', '=', 'employments.department_id')
            ->leftJoin('positions', 'positions.id', '=', 'employments.position_id')
            ->leftJoin('employment_types', 'employment_types.id', '=', 'employments.employment_type_id')
            ->leftJoin('salary_setups', 'salary_setups.employee_id', '=', 'employees.id')
            ->leftJoin('salary_types', 'salary_types.id', '=', 'salary_setups.salary_type_id')
            ->where('employees.status', true)
            ->orderBy('employees.lastname')
            ->orderBy('employees.firstname')
            ->get([
                'employees.id AS employee_id',
                'employees.emp_id',
                'employees.firstname',
                'employees.lastname',
                'employments.department_id',
                'departments.department_name',
                'positions.position',
                'employment_types.name AS employment_type',
                'locations.location_name',
                'locations.region_code',
                'locations.wage_classification',
                'salary_setups.basic_salary',
                'salary_types.type_name AS salary_type',
            ])
            ->unique('employee_id');

        // Open (pending/approved) salary adjustments, latest per employee.
        $open = DB::table('salary_adjustments')
            ->leftJoin('wage_orders', 'wage_orders.id', '=', 'salary_adjustments.wage_order_id')
            ->whereIn('salary_adjustments.employee_id', $employees->pluck('employee_id'))
            ->whereIn('salary_adjustments.status', ['pending', 'approved'])
            ->orderBy('salary_adjustments.id')
            ->get([
                'salary_adjustments.id',
                'salary_adjustments.employee_id',
                'salary_adjustments.status',
                'salary_adjustments.new_basic_salary',
                'salary_adjustments.effective_date',
                'salary_adjustments.wage_order_id',
                'wage_orders.order_no',
            ])
            ->keyBy('employee_id');

        return $employees->map(function ($e) use ($asOf, $open) {
            $region = $e->region_code;
            $class = $e->wage_classification ?: 'non_agriculture';
            $rate = $region ? $this->wages->currentRate($region, $class, $asOf) : null;
            $next = $region ? $this->wages->nextRate($region, $class, $asOf) : null;

            $hasSalary = $e->basic_salary !== null;
            $daily = $hasSalary ? round(WageRateService::dailyEquivalent((float) $e->basic_salary, $e->salary_type), 2) : null;
            $minimum = $rate ? (float) $rate->daily_rate : null;
            $previous = $rate && $rate->previous_rate !== null ? (float) $rate->previous_rate : null;
            $variance = $daily !== null && $minimum !== null ? round($daily - $minimum, 2) : null;
            $adjustment = $open->get($e->employee_id);

            $status = match (true) {
                ! $region => 'no_location',
                ! $hasSalary => 'no_salary',
                $minimum === null => 'no_wage_order',
                $variance < -0.005 => $adjustment ? 'pending' : 'below',
                $previous !== null && $minimum > $previous && $variance < ($minimum - $previous) - 0.005 => 'distortion',
                $next && $daily < (float) $next->daily_rate - 0.005 => 'upcoming',
                default => 'compliant',
            };

            $shortfallDaily = $variance !== null && $variance < 0 ? -$variance : 0.0;

            return [
                'employee_id' => $e->employee_id,
                'employee' => trim($e->firstname.' '.$e->lastname),
                'emp_id' => (string) $e->emp_id,
                'department_id' => $e->department_id ? (int) $e->department_id : null,
                'department' => $e->department_name ?? '—',
                'position' => $e->position ?? '—',
                'employment_type' => $e->employment_type,
                'location' => $e->location_name ?? '—',
                'region_code' => $region,
                'region_name' => WageRateService::regionName($region),
                'classification' => WageRateService::classificationName($class),
                'salary_type' => $e->salary_type ?? null,
                'basic_salary' => $hasSalary ? (float) $e->basic_salary : null,
                'current_daily' => $daily,
                'minimum_daily' => $minimum,
                'previous_minimum' => $previous,
                'variance_daily' => $variance,
                'monthly_shortfall' => round($shortfallDaily * WageRateService::WORKING_DAYS_PER_YEAR / 12, 2),
                'wage_order' => $rate ? [
                    'id' => $rate->wage_order_id,
                    'order_no' => $rate->wageOrder->order_no,
                    'title' => $rate->wageOrder->title,
                    'effective_date' => $rate->wageOrder->effective_date?->toDateString(),
                ] : null,
                'upcoming' => $next ? [
                    'id' => $next->wage_order_id,
                    'order_no' => $next->wageOrder->order_no,
                    'minimum_daily' => (float) $next->daily_rate,
                    'effective_date' => $next->wageOrder->effective_date?->toDateString(),
                ] : null,
                'adjustment' => $adjustment ? [
                    'id' => $adjustment->id,
                    'status' => $adjustment->status,
                    'new_basic_salary' => (float) $adjustment->new_basic_salary,
                    'effective_date' => $adjustment->effective_date,
                    'wage_order_no' => $adjustment->order_no,
                    'from_wage_order' => (bool) $adjustment->wage_order_id,
                ] : null,
                'status' => $status,
                'status_label' => self::STATUSES[$status],
            ];
        })->values();
    }
}