<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SalaryAdjustment;
use App\Models\SalarySetup;
use App\Models\WageOrder;
use App\Services\NotificationService;
use App\Services\WageRateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Apply a wage order to employees.
 *
 * Lists the active employees working in the wage order's region (by the
 * location of their latest employment). Employees earning less than the new
 * minimum come first and are the priority; HR / admin choose who to include
 * and how much. Each chosen employee gets a pending Salary Adjustment that
 * goes through the usual approve → apply flow before payroll uses it.
 *
 *   GET  /admin/wage-orders/{wageOrder}/employees?department_id=&location_id=&search=
 *   POST /admin/wage-orders/{wageOrder}/apply
 */
class WageOrderEmployeeController extends Controller
{
    public function index(Request $request, WageOrder $wageOrder): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $all = $this->employees($wageOrder);
        $search = mb_strtolower(trim((string) ($validated['search'] ?? '')));

        $rows = $all
            ->when($validated['department_id'] ?? null, fn ($c, $id) => $c->where('department_id', (int) $id))
            ->when($validated['location_id'] ?? null, fn ($c, $id) => $c->where('location_id', (int) $id))
            ->when($search !== '', fn ($c) => $c->filter(fn ($e) => str_contains(
                mb_strtolower($e['name'].' '.$e['emp_id'].' '.$e['department'].' '.$e['position']),
                $search
            )))
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Employees retrieved.',
            'data' => $rows,
            'stats' => [
                'employees' => $all->count(),
                'below_minimum' => $all->where('priority', true)->count(),
                'already_included' => $all->whereNotNull('included')->count(),
                'blocked' => $all->whereNotNull('blocked_reason')->count(),
            ],
            'departments' => $all->whereNotNull('department_id')
                ->unique('department_id')
                ->map(fn ($e) => ['id' => $e['department_id'], 'name' => $e['department']])
                ->sortBy('name')->values(),
            'locations' => $all->whereNotNull('location_id')
                ->unique('location_id')
                ->map(fn ($e) => ['id' => $e['location_id'], 'name' => $e['location']])
                ->sortBy('name')->values(),
        ]);
    }

    public function apply(Request $request, WageOrder $wageOrder): JsonResponse
    {
        if ($wageOrder->status !== 'published') {
            abort(409, 'Publish the wage order before applying it to employees.');
        }

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.employee_id' => ['required', 'integer', 'distinct'],
            'items.*.new_basic_salary' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ], [
            'items.required' => 'Select at least one employee.',
        ]);

        $eligible = $this->employees($wageOrder)->keyBy('employee_id');
        $reason = trim((string) ($validated['reason'] ?? '')) ?: "Wage Order {$wageOrder->order_no} ({$wageOrder->title}), effective {$wageOrder->effective_date->format('M j, Y')}.";

        $created = [];
        $skipped = [];

        DB::transaction(function () use ($validated, $eligible, $wageOrder, $reason, &$created, &$skipped) {
            foreach ($validated['items'] as $item) {
                $row = $eligible->get((int) $item['employee_id']);

                if (! $row) {
                    $skipped[] = ['employee_id' => $item['employee_id'], 'name' => null, 'reason' => 'Not an active employee in this region.'];
                    continue;
                }

                if ($row['blocked_reason']) {
                    $skipped[] = ['employee_id' => $row['employee_id'], 'name' => $row['name'], 'reason' => $row['blocked_reason']];
                    continue;
                }

                $setup = SalarySetup::query()->lockForUpdate()->find($row['salary_setup_id']);
                $new = round((float) $item['new_basic_salary'], 2);

                if ($new <= (float) $setup->basic_salary) {
                    $skipped[] = ['employee_id' => $row['employee_id'], 'name' => $row['name'], 'reason' => 'The new salary must be higher than the current salary.'];
                    continue;
                }

                $adjustment = SalaryAdjustment::create([
                    'employee_id' => $row['employee_id'],
                    'salary_setup_id' => $setup->id,
                    'adjustment_type' => 'increase',
                    'previous_basic_salary' => $setup->basic_salary,
                    'new_basic_salary' => $new,
                    'previous_allowance' => $setup->allowance,
                    'new_allowance' => $setup->allowance,
                    'effective_date' => $wageOrder->effective_date->toDateString(),
                    'reason' => $reason,
                    'status' => 'pending',
                ]);

                $adjustment->forceFill(['wage_order_id' => $wageOrder->id])->save();

                $created[] = ['employee_id' => $row['employee_id'], 'name' => $row['name'], 'salary_adjustment_id' => $adjustment->id];
            }
        });

        if ($created) {
            app(NotificationService::class)->toAdmins(
                category: 'salary',
                title: 'Wage order applied',
                message: count($created).' salary adjustment(s) from '.$wageOrder->order_no.' are waiting for approval.',
                url: '/admin/settings/compensation/wage-adjustments',
                level: 'info',
                exceptUserId: $request->user()?->id,
                subjectType: 'wage_order',
                subjectId: $wageOrder->id,
            );
        }

        return response()->json([
            'success' => true,
            'message' => count($created).' salary adjustment(s) created'.($skipped ? ', '.count($skipped).' skipped.' : '.'),
            'data' => [
                'created' => $created,
                'skipped' => $skipped,
            ],
        ], $created ? 201 : 200);
    }

    // =====================================================
    // EMPLOYEES OF THE REGION
    // =====================================================

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private ?int $currentOrderId = null;

    private function employees(WageOrder $wageOrder): Collection
    {
        $this->currentOrderId = $wageOrder->id;
        $wageOrder->loadMissing('rates');
        $rates = $wageOrder->rates->keyBy('classification');

        $rows = DB::table('employees')
            ->join('employments', function ($join) {
                $join->on('employments.employee_id', '=', 'employees.id')
                    ->whereRaw('employments.id = (SELECT MAX(e2.id) FROM employments e2 WHERE e2.employee_id = employees.id)');
            })
            ->join('locations', 'locations.id', '=', 'employments.location_id')
            ->leftJoin('departments', 'departments.id', '=', 'employments.department_id')
            ->leftJoin('positions', 'positions.id', '=', 'employments.position_id')
            ->leftJoin('salary_setups', 'salary_setups.employee_id', '=', 'employees.id')
            ->leftJoin('salary_types', 'salary_types.id', '=', 'salary_setups.salary_type_id')
            ->where('employees.status', true)
            ->where('locations.region_code', $wageOrder->region_code)
            ->orderBy('employees.lastname')
            ->get([
                'employees.id AS employee_id',
                'employees.emp_id',
                'employees.firstname',
                'employees.lastname',
                'employments.department_id',
                'departments.department_name',
                'positions.position',
                'locations.id AS location_id',
                'locations.location_name',
                'locations.wage_classification',
                'salary_setups.id AS salary_setup_id',
                'salary_setups.basic_salary',
                'salary_types.type_name AS salary_type',
            ]);

        $employeeIds = $rows->pluck('employee_id');

        // Open adjustments block a new one (one pending/approved per employee).
        $open = SalaryAdjustment::query()
            ->leftJoin('wage_orders', 'wage_orders.id', '=', 'salary_adjustments.wage_order_id')
            ->whereIn('salary_adjustments.employee_id', $employeeIds)
            ->whereIn('salary_adjustments.status', ['pending', 'approved'])
            ->get([
                'salary_adjustments.id',
                'salary_adjustments.employee_id',
                'salary_adjustments.status',
                'salary_adjustments.adjustment_type',
                'salary_adjustments.wage_order_id',
                'salary_adjustments.previous_basic_salary',
                'salary_adjustments.new_basic_salary',
                'salary_adjustments.effective_date',
                'wage_orders.order_no',
            ])
            ->keyBy('employee_id');

        // Already included in this wage order (any status except rejected).
        $included = SalaryAdjustment::query()
            ->where('wage_order_id', $wageOrder->id)
            ->where('status', '!=', 'rejected')
            ->get(['id', 'employee_id', 'status'])
            ->keyBy('employee_id');

        return $rows->map(function ($r) use ($rates, $open, $included) {
            $classification = $r->wage_classification ?: 'non_agriculture';
            $rate = $rates->get($classification);
            $hasSalary = $r->salary_setup_id && $r->basic_salary !== null;

            $currentDaily = $hasSalary ? round(WageRateService::dailyEquivalent((float) $r->basic_salary, $r->salary_type), 2) : null;
            $minimum = $rate ? (float) $rate->daily_rate : null;
            $increase = $rate && $rate->previous_rate !== null ? max(0, (float) $rate->daily_rate - (float) $rate->previous_rate) : 0.0;

            $status = match (true) {
                ! $hasSalary || $minimum === null => 'unknown',
                $currentDaily < $minimum - 0.005 => 'below',
                $currentDaily <= $minimum + 0.005 => 'at',
                default => 'above',
            };

            // Below the new minimum: raise to the minimum. Others: current
            // salary plus the wage order increase (only if HR includes them).
            // Added to the current salary directly so it is not changed by
            // rounding when there is no increase.
            $suggested = match (true) {
                $status === 'below' => WageRateService::fromDaily($minimum, $r->salary_type),
                in_array($status, ['at', 'above'], true) => round(
                    (float) $r->basic_salary + ($increase > 0 ? WageRateService::fromDaily($increase, $r->salary_type) : 0),
                    2
                ),
                default => null,
            };

            $blocked = match (true) {
                isset($included[$r->employee_id]) => 'Already included in this wage order (adjustment #'.$included[$r->employee_id]->id.', '.$included[$r->employee_id]->status.').',
                isset($open[$r->employee_id]) => 'Has a '.$open[$r->employee_id]->status.' salary adjustment (#'.$open[$r->employee_id]->id.').',
                ! $hasSalary => 'No salary rate set up.',
                default => null,
            };

            return [
                'employee_id' => $r->employee_id,
                'emp_id' => (string) $r->emp_id,
                'name' => trim($r->firstname.' '.$r->lastname),
                'department_id' => $r->department_id ? (int) $r->department_id : null,
                'department' => $r->department_name,
                'position' => $r->position,
                'location_id' => (int) $r->location_id,
                'location' => $r->location_name,
                'classification' => $classification,
                'classification_name' => WageRateService::classificationName($classification),
                'salary_setup_id' => $r->salary_setup_id,
                'salary_type' => $r->salary_type ?? 'Monthly',
                'current_salary' => $hasSalary ? (float) $r->basic_salary : null,
                'current_daily' => $currentDaily,
                'new_minimum' => $minimum,
                'cola' => $rate ? (float) $rate->cola : 0.0,
                'status' => $status,
                'priority' => $status === 'below',
                'shortfall_daily' => $status === 'below' ? round($minimum - $currentDaily, 2) : 0.0,
                'suggested_salary' => $suggested,
                'included' => isset($included[$r->employee_id]) ? $included[$r->employee_id]->id : null,
                'blocked_reason' => $blocked,
                // The adjustment that blocks this employee, so HR can review or delete it here.
                'blocking' => isset($open[$r->employee_id]) ? [
                    'id' => $open[$r->employee_id]->id,
                    'status' => $open[$r->employee_id]->status,
                    'type' => $open[$r->employee_id]->adjustment_type,
                    'source' => $open[$r->employee_id]->wage_order_id ? 'wage_order' : 'manual',
                    'order_no' => $open[$r->employee_id]->order_no,
                    'this_order' => (int) $open[$r->employee_id]->wage_order_id === (int) $this->currentOrderId,
                    'previous_salary' => (float) $open[$r->employee_id]->previous_basic_salary,
                    'new_salary' => (float) $open[$r->employee_id]->new_basic_salary,
                    'effective_date' => $open[$r->employee_id]->effective_date instanceof \DateTimeInterface
                        ? $open[$r->employee_id]->effective_date->format('Y-m-d')
                        : (string) $open[$r->employee_id]->effective_date,
                ] : null,
            ];
        })
            // Priority (below minimum, biggest shortfall) first.
            ->sortBy([['priority', 'desc'], ['shortfall_daily', 'desc'], ['name', 'asc']])
            ->values();
    }
}