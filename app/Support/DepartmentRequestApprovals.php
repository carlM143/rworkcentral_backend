<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class DepartmentRequestApprovals
{
    public static function canApprove(User $user, string $type): bool
    {
        [$module, $feature] = match ($type) {
            'leave' => ['approvals', 'leaves'],
            'overtime' => ['approvals', 'overtime'],
            'undertime' => ['approvals', 'undertime'],
            'oba' => ['approvals', 'oba'],
            'wfh' => ['wfh', 'requests'],
            default => [null, null],
        };
        return $module !== null && $user->hasPrivilege($module, "{$feature}.view")
            && ($user->hasPrivilege($module, "{$feature}.edit") || $user->hasPrivilege($module, "{$feature}.approve"));
    }

    public static function records(string $type, int $id)
    {
        return DB::table('department_request_approvals')->where('request_type', $type)->where('request_id', $id);
    }

    /** Called while the parent request is locked; existing snapshots are immutable. */
    public static function initialize(string $type, object $request): void
    {
        if (self::records($type, $request->id)->exists()) {
            return;
        }
        $department = DepartmentHead::departmentOf((int) $request->employee_id);
        $employees = DB::table('department_approvers as assignments')
            ->join('employees', 'employees.id', '=', 'assignments.employee_id')
            ->where('assignments.department_id', $department)
            ->where('employees.status', true)
            ->where('employees.id', '!=', $request->employee_id)
            ->get(['employees.id', 'employees.user_id', 'employees.firstname', 'employees.lastname']);
        foreach ($employees as $employee) {
            $user = User::find($employee->user_id);
            if (! $user || ! self::canApprove($user, $type)
                || DepartmentHead::departmentOf((int) $employee->id) !== $department) {
                continue;
            }
            $position = DB::table('employments')->leftJoin('positions', 'positions.id', '=', 'employments.position_id')
                ->where('employments.employee_id', $employee->id)->orderByDesc('employments.id')->value('positions.position');
            self::records($type, $request->id)->insert([
                'request_type' => $type, 'request_id' => $request->id,
                'department_id' => $department, 'employee_id' => $employee->id,
                'user_id' => $user->id, 'approver_name' => trim($employee->firstname.' '.$employee->lastname),
                'approver_position' => $position, 'status' => 'pending',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
