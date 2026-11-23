<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Support\DepartmentHead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Departments › Department Heads: who approves each department's requests.
 *
 *   GET   /admin/department-heads
 *   PATCH /admin/department-heads/{department}   { head_employee_id | null }
 */
class DepartmentHeadController extends Controller
{
    public function index(): JsonResponse
    {
        $departments = DB::table('departments')
            ->leftJoin('employees AS head', 'head.id', '=', 'departments.head_employee_id')
            ->leftJoin('users AS head_user', 'head_user.id', '=', 'head.user_id')
            ->leftJoin('roles', 'roles.id', '=', 'head_user.role_id')
            ->orderBy('departments.department_name')
            ->get([
                'departments.id',
                'departments.department_name',
                'departments.status',
                'departments.head_employee_id',
                'head.firstname',
                'head.lastname',
                'head.emp_id',
                'head.user_id',
                'roles.name AS role_name',
            ]);

        // Employees per department (latest employment), for the head dropdown.
        $members = DB::table('employees')
            ->join('employments', function ($join) {
                $join->on('employments.employee_id', '=', 'employees.id')
                    ->whereRaw('employments.id = (SELECT MAX(e2.id) FROM employments e2 WHERE e2.employee_id = employees.id)');
            })
            ->leftJoin('positions', 'positions.id', '=', 'employments.position_id')
            ->where('employees.status', true)
            ->orderBy('employees.lastname')
            ->get([
                'employees.id',
                'employees.emp_id',
                'employees.firstname',
                'employees.lastname',
                'employees.user_id',
                'employments.department_id',
                'positions.position',
            ])
            ->groupBy('department_id');

        $pending = collect(['leave_requests', 'overtime_requests', 'undertime_requests'])
            ->flatMap(fn ($t) => DB::table($t)->where('dept_head_status', 'pending')->where('status', 'pending')->pluck('dept_head_id'))
            ->countBy();

        return response()->json([
            'success' => true,
            'message' => 'Department heads retrieved.',
            'data' => $departments->map(fn ($d) => [
                'id' => $d->id,
                'name' => $d->department_name,
                'active' => (bool) $d->status,
                'head' => $d->head_employee_id ? [
                    'employee_id' => (int) $d->head_employee_id,
                    'name' => trim($d->firstname.' '.$d->lastname),
                    'emp_id' => $d->emp_id,
                    'has_account' => (bool) $d->user_id,
                    'role' => $d->role_name,
                    'pending' => (int) ($pending[$d->user_id] ?? 0),
                ] : null,
                'members' => ($members[$d->id] ?? collect())->map(fn ($m) => [
                    'employee_id' => $m->id,
                    'name' => trim($m->firstname.' '.$m->lastname),
                    'emp_id' => $m->emp_id,
                    'position' => $m->position,
                    'has_account' => (bool) $m->user_id,
                ])->values(),
            ]),
        ]);
    }

    public function update(Request $request, int $department): JsonResponse
    {
        $validated = $request->validate([
            'head_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ]);

        abort_unless(DB::table('departments')->where('id', $department)->exists(), 404, 'Department not found.');

        $headId = $validated['head_employee_id'] ?? null;

        if ($headId && DepartmentHead::departmentOf((int) $headId) !== $department) {
            abort(422, 'The head must belong to this department.');
        }

        DB::table('departments')->where('id', $department)->update([
            'head_employee_id' => $headId,
            'updated_at' => now(),
        ]);

        $warning = null;
        if ($headId && ! DB::table('employees')->where('id', $headId)->value('user_id')) {
            $warning = 'This employee has no user account yet, so requests will go straight to HR. Create an account in Users & Access › System Users.';
        }

        return response()->json([
            'success' => true,
            'message' => $headId ? 'Department head saved.' : 'Department head removed.',
            'warning' => $warning,
            'data' => null,
        ]);
    }
}