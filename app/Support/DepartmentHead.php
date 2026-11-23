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
        static $ids = null;

        return $ids ??= DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->join('role_privileges', 'role_privileges.role_id', '=', 'roles.id')
            ->join('privileges', 'privileges.id', '=', 'role_privileges.privilege_id')
            ->where('privileges.module', 'approvals')
            ->where('privileges.name', 'first_level.approve')
            ->whereRaw('LOWER(roles.name) <> ?', ['administrator'])
            ->distinct()
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->all();
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