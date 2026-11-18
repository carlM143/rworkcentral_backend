<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SalaryAdjustment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Illuminate\Validation\ValidationException;
use Throwable;

class WageAdjustmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'wage_order_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:pending,approved,applied,rejected'],
            'department_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));

        $base = DB::table('salary_adjustments')
            ->join('wage_orders', 'wage_orders.id', '=', 'salary_adjustments.wage_order_id')
            ->join('employees', 'employees.id', '=', 'salary_adjustments.employee_id')
            ->leftJoin('employments', function ($join) {
                $join->on('employments.employee_id', '=', 'employees.id')
                    ->whereRaw('employments.id = (SELECT MAX(e2.id) FROM employments e2 WHERE e2.employee_id = employees.id)');
            })
            ->leftJoin('departments', 'departments.id', '=', 'employments.department_id')
            ->leftJoin('positions', 'positions.id', '=', 'employments.position_id')
            ->leftJoin('salary_setups', 'salary_setups.id', '=', 'salary_adjustments.salary_setup_id')
            ->leftJoin('salary_types', 'salary_types.id', '=', 'salary_setups.salary_type_id')
            ->leftJoin('users AS approver', 'approver.id', '=', 'salary_adjustments.approved_by')
            ->whereNotNull('salary_adjustments.wage_order_id');

        $rows = (clone $base)
            ->when($validated['wage_order_id'] ?? null, fn ($q, $id) => $q->where('salary_adjustments.wage_order_id', $id))
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('salary_adjustments.status', $status))
            ->when($validated['department_id'] ?? null, fn ($q, $id) => $q->where('employments.department_id', $id))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.$search.'%';
                $q->where(fn ($w) => $w->where('employees.firstname', 'like', $like)
                    ->orWhere('employees.lastname', 'like', $like)
                    ->orWhere('employees.emp_id', 'like', $like)
                    ->orWhere('wage_orders.order_no', 'like', $like));
            })
            ->orderByRaw("FIELD(salary_adjustments.status, 'pending', 'approved', 'applied', 'rejected')")
            ->orderBy('employees.lastname')
            ->get([
                'salary_adjustments.id',
                'salary_adjustments.status',
                'salary_adjustments.previous_basic_salary',
                'salary_adjustments.new_basic_salary',
                'salary_adjustments.effective_date',
                'salary_adjustments.approved_at',
                'salary_adjustments.applied_at',
                'salary_adjustments.reason',
                'wage_orders.id AS wage_order_id',
                'wage_orders.order_no',
                'employees.id AS employee_id',
                'employees.emp_id',
                'employees.firstname',
                'employees.lastname',
                'employments.department_id',
                'departments.department_name',
                'positions.position',
                'salary_types.type_name AS salary_type',
                'approver.name AS approved_by_name',
            ])
            ->map(fn ($r) => [
                'id' => $r->id,
                'status' => $r->status,
                'employee_id' => $r->employee_id,
                'emp_id' => (string) $r->emp_id,
                'name' => trim($r->firstname.' '.$r->lastname),
                'department_id' => $r->department_id ? (int) $r->department_id : null,
                'department' => $r->department_name,
                'position' => $r->position,
                'salary_type' => $r->salary_type ?? 'Monthly',
                'previous_salary' => (float) $r->previous_basic_salary,
                'new_salary' => (float) $r->new_basic_salary,
                'increase' => round((float) $r->new_basic_salary - (float) $r->previous_basic_salary, 2),
                'effective_date' => $r->effective_date,
                'can_apply_now' => $r->status === 'approved' && $r->effective_date <= today()->toDateString(),
                'approved_by' => $r->approved_by_name,
                'approved_at' => $r->approved_at,
                'applied_at' => $r->applied_at,
                'wage_order_id' => $r->wage_order_id,
                'order_no' => $r->order_no,
            ]);

        $counts = (clone $base)
            ->when($validated['wage_order_id'] ?? null, fn ($q, $id) => $q->where('salary_adjustments.wage_order_id', $id))
            ->groupBy('salary_adjustments.status')
            ->selectRaw('salary_adjustments.status, COUNT(*) AS total')
            ->pluck('total', 'status');

        return response()->json([
            'success' => true,
            'message' => 'Wage adjustments retrieved.',
            'data' => $rows->values(),
            'stats' => [
                'pending' => (int) ($counts['pending'] ?? 0),
                'approved' => (int) ($counts['approved'] ?? 0),
                'applied' => (int) ($counts['applied'] ?? 0),
                'rejected' => (int) ($counts['rejected'] ?? 0),
            ],
            'wage_orders' => DB::table('wage_orders')
                ->whereIn('id', DB::table('salary_adjustments')->whereNotNull('wage_order_id')->select('wage_order_id'))
                ->orderByDesc('effective_date')
                ->get(['id', 'order_no', 'title']),
            'departments' => $rows->whereNotNull('department_id')
                ->unique('department_id')
                ->map(fn ($r) => ['id' => $r['department_id'], 'name' => $r['department']])
                ->sortBy('name')->values(),
        ]);
    }

    public function bulk(Request $request, SalaryAdjustmentController $salaryAdjustments): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'in:approve,reject,apply'],
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct'],
        ]);

        $items = SalaryAdjustment::query()
            ->whereIn('id', $validated['ids'])
            ->whereNotNull('wage_order_id')
            ->with('employee:id,firstname,lastname')
            ->get();

        $done = [];
        $failed = [];

        foreach ($items as $item) {
            $name = trim(($item->employee?->firstname ?? '').' '.($item->employee?->lastname ?? ''));

            try {
                match ($validated['action']) {
                    'approve' => $salaryAdjustments->approve($request, $item),
                    'reject' => $salaryAdjustments->reject($item),
                    'apply' => $salaryAdjustments->apply($request, $item),
                };
                $done[] = ['id' => $item->id, 'name' => $name];
            } catch (HttpExceptionInterface $e) {
                $failed[] = ['id' => $item->id, 'name' => $name, 'reason' => $e->getMessage()];
            } catch (ValidationException $e) {
                $failed[] = ['id' => $item->id, 'name' => $name, 'reason' => collect($e->errors())->flatten()->first()];
            } catch (Throwable $e) {
                report($e);
                $failed[] = ['id' => $item->id, 'name' => $name, 'reason' => 'Unexpected error.'];
            }
        }

        $verb = ['approve' => 'approved', 'reject' => 'rejected', 'apply' => 'applied'][$validated['action']];

        return response()->json([
            'success' => true,
            'message' => count($done)." adjustment(s) {$verb}".($failed ? ', '.count($failed).' not changed.' : '.'),
            'data' => ['done' => $done, 'failed' => $failed],
        ]);
    }
}