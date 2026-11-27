<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DepartmentHead
{

    public static function departmentOf(int $employeeId): ?int
    {
        $id = DB::table('employments')
            ->where('employee_id', $employeeId)
            ->orderByDesc('id')
            ->value('department_id');

        return $id ? (int) $id : null;
    }

    public static function approverUserIdFor(int $employeeId): ?int
    {
        $departmentId = self::departmentOf($employeeId);

        if (! $departmentId) {
            return null;
        }

        $headEmployeeId = DB::table('departments')->where('id', $departmentId)->value('head_employee_id');

        if (! $headEmployeeId || (int) $headEmployeeId === $employeeId) {
            return null;
        }

        $userId = DB::table('employees')->where('id', $headEmployeeId)->value('user_id');

        return $userId ? (int) $userId : null;
    }

    /**
     * Departments the user heads (by the employee linked to the user).
     *
     * @return Collection<int, object{id: int, department_name: string}>
     */
    public static function departmentsHeadedBy(int $userId): Collection
    {
        $employeeId = DB::table('employees')->where('user_id', $userId)->value('id');

        if (! $employeeId) {
            return collect();
        }

        return DB::table('departments')
            ->where('head_employee_id', $employeeId)
            ->orderBy('department_name')
            ->get(['id', 'department_name']);
    }

    public static function supervisorUserIds(): array
    {
        return \App\Models\User::whereIn('id', DB::table('department_approvers')
            ->join('employees', 'employees.id', '=', 'department_approvers.employee_id')
            ->where('employees.status', true)->pluck('employees.user_id'))
            ->get()->filter(function ($user) {
                foreach (['leave', 'overtime', 'undertime', 'oba', 'wfh'] as $type) {
                    if (DepartmentRequestApprovals::canApprove($user, $type)) return true;
                }
                return false;
            })->pluck('id')->all();
    }

    public static function nameOfUser(?int $userId): ?string
    {
        if (! $userId) {
            return null;
        }

        $employee = DB::table('employees')->where('user_id', $userId)->first(['firstname', 'lastname']);

        return $employee
            ? trim($employee->firstname.' '.$employee->lastname)
            : DB::table('users')->where('id', $userId)->value('name');
    }
}
