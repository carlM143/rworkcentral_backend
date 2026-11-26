<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DepartmentApproverController extends Controller
{
    public function index(Department $department): JsonResponse
    {
        $this->checkCompany($department);
        $assigned = DB::table('department_approvers')
            ->where('department_id', $department->id)->pluck('employee_id')->all();

        $employees = DB::table('employees')
            ->join('employments', function ($join) {
                $join->on('employments.employee_id', '=', 'employees.id')
                    ->whereRaw('employments.id = (SELECT MAX(e2.id) FROM employments e2 WHERE e2.employee_id = employees.id)');
            })
            ->leftJoin('positions', 'positions.id', '=', 'employments.position_id')
            ->where('employments.department_id', $department->id)
            ->orderBy('employees.lastname')
            ->get(['employees.id', 'employees.firstname', 'employees.lastname', 'employees.status', 'employees.user_id', 'positions.position']);

        $users = User::with('role')->whereIn('id', $employees->pluck('user_id')->filter())->get()->keyBy('id');

        return response()->json(['data' => $employees->map(function ($employee) use ($assigned, $users) {
            $user = $users->get($employee->user_id);
            return [
                'id' => $employee->id,
                'name' => trim($employee->firstname.' '.$employee->lastname),
                'position' => $employee->position,
                'assigned' => in_array($employee->id, $assigned),
                'eligible' => $this->isEligible((bool) $employee->status, $user),
            ];
        })->values()]);
    }

    public function update(Request $request, Department $department): JsonResponse
    {
        $this->checkCompany($department);
        $validated = $request->validate([
            'employee_ids' => ['present', 'array'],
            'employee_ids.*' => ['integer', 'distinct', 'exists:employees,id'],
        ]);

        $ids = $validated['employee_ids'];
        $members = DB::table('employments')
            ->where('department_id', $department->id)
            ->whereRaw('id = (SELECT MAX(e2.id) FROM employments e2 WHERE e2.employee_id = employments.employee_id)')
            ->whereIn('employee_id', $ids)->pluck('employee_id')->map(fn ($id) => (int) $id)->all();
        if (count($members) !== count($ids)) {
            throw ValidationException::withMessages(['employee_ids' => 'Approvers must belong to this department.']);
        }

        $employees = DB::table('employees')->whereIn('id', $ids)->get(['id', 'status', 'user_id']);
        $users = User::with('role')->whereIn('id', $employees->pluck('user_id')->filter())->get()->keyBy('id');
        foreach ($employees as $employee) {
            if (! $this->isEligible((bool) $employee->status, $users->get($employee->user_id))) {
                throw ValidationException::withMessages([
                    'employee_ids' => 'Remove ineligible employees before saving. Approvers must be active and have both For My Approval permissions.',
                ]);
            }
        }

        DB::transaction(function () use ($department, $ids) {
            DB::table('department_approvers')->where('department_id', $department->id)->delete();
            $now = now();
            foreach ($ids as $id) {
                DB::table('department_approvers')->insert([
                    'department_id' => $department->id, 'employee_id' => $id,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        });

        return response()->json(['message' => 'Department approvers saved.']);
    }

    private function checkCompany(Department $department): void
    {
        abort_unless($department->company_id === Company::query()->sole()->id, 404);
    }

    private function isEligible(bool $active, ?User $user): bool
    {
        return $active && $user !== null
            && $user->hasPrivilege('approvals', 'first_level.view')
            && $user->hasPrivilege('approvals', 'first_level.approve');
    }
}
