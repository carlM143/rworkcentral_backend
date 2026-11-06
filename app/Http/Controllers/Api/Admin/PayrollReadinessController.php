<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeDepartmentTransfer;
use App\Models\EmployeePayrollGroupAssignment;
use App\Models\LateAdjustment;
use App\Models\LeaveRequestDate;
use App\Models\PayrollGroup;
use App\Models\PayrollPeriod;
use App\Models\TimeLogCorrectionRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class PayrollReadinessController extends Controller
{
    private const SEMI_MONTHLY = 'semi_monthly';

    private ?string $leaveDateColumn = null;

    private function companyId(Request $request): int
    {
        abort_unless($request->user(), 401, 'Unauthenticated.');

        if ($request->user()->company_id) {
            return (int) $request->user()->company_id;
        }

        return (int) Company::query()->sole()->id;
    }

    private function departmentName($department): ?string
    {
        if (!$department) {
            return null;
        }

        return $department->department_name ?? $department->name ?? null;
    }

    private function leaveDateColumn(): ?string
    {
        if ($this->leaveDateColumn !== null) {
            return $this->leaveDateColumn ?: null;
        }

        $table = (new LeaveRequestDate())->getTable();

        $this->leaveDateColumn = collect(['leave_date', 'date', 'work_date'])
            ->first(fn ($column) => Schema::hasColumn($table, $column)) ?? '';

        return $this->leaveDateColumn ?: null;
    }

    private function pendingLeaveInCutoff(Builder $query, string $start, string $end): Builder
    {
        $query->where('status', 'pending');

        $column = $this->leaveDateColumn();

        if ($column) {
            return $query->whereHas(
                'leaveDates',
                fn ($q) => $q->whereBetween($column, [$start, $end])
            );
        }

        return $query->whereBetween('request_date', [$start, $end]);
    }

    private function countByEmployee(Builder $query): Collection
    {
        return $query
            ->selectRaw('employee_id, COUNT(*) as total')
            ->groupBy('employee_id')
            ->pluck('total', 'employee_id');
    }

    // GET /api/admin/payroll-readiness?payroll_period_id=7&payroll_group_id=
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payroll_period_id' => ['required', 'integer', 'exists:payroll_periods,id'],
            'payroll_group_id' => ['nullable', 'integer', 'exists:payroll_groups,id'],
        ]);

        $groupFilter = isset($data['payroll_group_id']) ? (int) $data['payroll_group_id'] : null;

        $period = PayrollPeriod::query()
            ->where('company_id', $this->companyId($request))
            ->findOrFail($data['payroll_period_id']);

        $start = $period->cutoff_start->format('Y-m-d');
        $end = $period->cutoff_end->format('Y-m-d');

        $assignmentsByEmployee = EmployeePayrollGroupAssignment::query()
            ->with('payrollGroup:id,group_name,frequency,is_active')
            ->whereDate('effective_from', '<=', $end)
            ->where(fn ($q) => $q->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $start))
            ->orderBy('effective_from')
            ->get()
            ->groupBy('employee_id');

        $employeeIds = $assignmentsByEmployee
            ->filter(fn ($history) => $groupFilter === null
                || (int) $history->last()->payroll_group_id === $groupFilter)
            ->keys()
            ->values();

        $employees = Employee::query()
            ->whereIn('id', $employeeIds)
            ->select('id', 'emp_id', 'firstname', 'lastname', 'department_id', 'status')
            ->with(['department', 'salarySetup.salaryType'])
            ->withCount([
                'timeLogs as time_logs_count' => fn ($q) => $q
                    ->whereBetween('work_date', [$start, $end]),
                'timeLogs as incomplete_logs_count' => fn ($q) => $q
                    ->whereBetween('work_date', [$start, $end])
                    ->where(fn ($q) => $q->whereNull('time_in')->orWhereNull('time_out')),
                'overtimeRequests as pending_overtime_count' => fn ($q) => $q
                    ->where('status', 'pending')
                    ->whereBetween('ot_date', [$start, $end]),
                'undertimeRequests as pending_undertime_count' => fn ($q) => $q
                    ->where('status', 'pending')
                    ->whereBetween('undertime_date', [$start, $end]),
                'leaveRequests as pending_leave_count' => fn ($q) => $this
                    ->pendingLeaveInCutoff($q, $start, $end),
            ])
            ->orderBy('lastname')
            ->orderBy('firstname')
            ->get();

        $pendingCorrections = $this->countByEmployee(
            TimeLogCorrectionRequest::query()
                ->whereIn('employee_id', $employeeIds)
                ->where('status', 'pending')
                ->whereHas('timeLog', fn ($q) => $q->whereBetween('work_date', [$start, $end]))
        );

        $pendingLateAdjustments = $this->countByEmployee(
            LateAdjustment::query()
                ->whereIn('employee_id', $employeeIds)
                ->where('status', 'pending')
                ->whereHas('timeLog', fn ($q) => $q->whereBetween('work_date', [$start, $end]))
        );

        $pendingTransfers = $this->countByEmployee(
            EmployeeDepartmentTransfer::query()
                ->whereIn('employee_id', $employeeIds)
                ->whereNull('applied_at')
                ->whereNotIn('status', ['rejected', 'cancelled', 'applied'])
                ->whereDate('effective_date', '<=', $end)
        );

        $rows = $employees->map(function (Employee $employee) use (
            $assignmentsByEmployee,
            $pendingCorrections,
            $pendingLateAdjustments,
            $pendingTransfers
        ) {
            $history = $assignmentsByEmployee->get($employee->id, collect());
            $group = $history->last()?->payrollGroup;
            $salary = $employee->salarySetup;

            $issues = [];
            $add = function (string $severity, string $code, string $message) use (&$issues) {
                $issues[] = compact('severity', 'code', 'message');
            };

            if (!$salary) {
                $add('blocker', 'no_salary_setup', 'No salary setup.');
            } elseif ((float) $salary->basic_salary <= 0) {
                $add('blocker', 'no_basic_salary', 'Basic salary is not set.');
            } elseif (!$salary->salary_type_id) {
                $add('blocker', 'no_salary_type', 'Salary type is not set.');
            } elseif (!$salary->bank_name || !$salary->account_number) {
                $add('warning', 'no_bank_details', 'Bank details are incomplete.');
            }

            if (!$employee->status) {
                $add('warning', 'inactive_employee', 'Employee is inactive. Check final pay.');
            }

            if ($history->count() > 1) {
                $add('warning', 'moved_mid_cutoff', 'Changed payroll group within this cutoff.');
            }

            if ($group && !$group->is_active) {
                $add('warning', 'inactive_group', 'Assigned payroll group is inactive.');
            }

            $timeLogs = (int) $employee->time_logs_count;
            $incomplete = (int) $employee->incomplete_logs_count;

            if ($timeLogs === 0) {
                $add('warning', 'no_time_logs', 'No time logs in this cutoff.');
            } elseif ($incomplete > 0) {
                $add('warning', 'incomplete_time_logs', "{$incomplete} time log(s) missing time in or time out.");
            }

            $pending = [
                'overtime' => (int) $employee->pending_overtime_count,
                'undertime' => (int) $employee->pending_undertime_count,
                'leave' => (int) $employee->pending_leave_count,
                'corrections' => (int) ($pendingCorrections[$employee->id] ?? 0),
                'late_adjustments' => (int) ($pendingLateAdjustments[$employee->id] ?? 0),
                'transfers' => (int) ($pendingTransfers[$employee->id] ?? 0),
            ];

            $labels = [
                'overtime' => 'overtime request',
                'undertime' => 'undertime request',
                'leave' => 'leave request',
                'corrections' => 'time log correction',
                'late_adjustments' => 'late adjustment',
                'transfers' => 'department transfer',
            ];

            foreach ($pending as $key => $count) {
                if ($count > 0) {
                    $add('warning', "pending_{$key}", "{$count} pending {$labels[$key]}(s).");
                }
            }

            $hasBlocker = collect($issues)->contains('severity', 'blocker');

            return [
                'employee_id' => $employee->id,
                'emp_id' => $employee->emp_id,
                'name' => trim("{$employee->lastname}, {$employee->firstname}"),
                'department' => $this->departmentName($employee->department),
                'is_active' => (bool) $employee->status,
                'payroll_group' => $group ? [
                    'id' => $group->id,
                    'group_name' => $group->group_name,
                    'frequency' => $group->frequency,
                ] : null,
                'basic_salary' => $salary?->basic_salary,
                'allowance' => $salary?->allowance,
                'salary_type' => $salary?->salaryType?->type_name
                    ?? $salary?->salaryType?->name
                    ?? null,
                'time_logs_count' => $timeLogs,
                'incomplete_logs_count' => $incomplete,
                'pending' => $pending,
                'status' => $hasBlocker ? 'blocked' : (count($issues) ? 'warning' : 'ready'),
                'issues' => $issues,
            ];
        })->values();

        $unassigned = $groupFilter !== null
            ? collect()
            : Employee::query()
                ->where('status', true)
                ->whereNotIn('id', $assignmentsByEmployee->keys())
                ->with('department')
                ->orderBy('lastname')
                ->orderBy('firstname')
                ->get(['id', 'emp_id', 'firstname', 'lastname', 'department_id'])
                ->map(fn (Employee $employee) => [
                    'employee_id' => $employee->id,
                    'emp_id' => $employee->emp_id,
                    'name' => trim("{$employee->lastname}, {$employee->firstname}"),
                    'department' => $this->departmentName($employee->department),
                ])
                ->values();

        $groups = PayrollGroup::query()
            ->where('is_active', true)
            ->when($groupFilter, fn ($q) => $q->where('id', $groupFilter))
            ->orderBy('group_name')
            ->get(['id', 'group_name', 'frequency'])
            ->map(function (PayrollGroup $group) use ($rows) {
                $members = $rows->where('payroll_group.id', $group->id);

                return [
                    'id' => $group->id,
                    'group_name' => $group->group_name,
                    'frequency' => $group->frequency,
                    'employees' => $members->count(),
                    'ready' => $members->where('status', 'ready')->count(),
                    'warning' => $members->where('status', 'warning')->count(),
                    'blocked' => $members->where('status', 'blocked')->count(),
                    'frequency_mismatch' => $group->frequency !== self::SEMI_MONTHLY,
                ];
            })
            ->values();

        $periodIssues = [];
        $status = strtolower((string) $period->status);

        if ($status === 'draft') {
            $periodIssues[] = [
                'severity' => 'blocker',
                'code' => 'period_not_open',
                'message' => 'This period is still a draft. Open it before generating payroll.',
            ];
        }

        if ($status === 'closed') {
            $periodIssues[] = [
                'severity' => 'blocker',
                'code' => 'period_closed',
                'message' => 'This period is closed. Payroll can no longer be generated for it.',
            ];
        }

        if ($unassigned->isNotEmpty()) {
            $periodIssues[] = [
                'severity' => 'blocker',
                'code' => 'unassigned_employees',
                'message' => "{$unassigned->count()} active employee(s) have no payroll group for this cutoff and would not be paid.",
            ];
        }

        $mismatched = $groups->where('frequency_mismatch', true)->where('employees', '>', 0);

        if ($mismatched->isNotEmpty()) {
            $periodIssues[] = [
                'severity' => 'warning',
                'code' => 'frequency_mismatch',
                'message' => 'Some groups are not paid semi-monthly but have employees in this period: '
                    . $mismatched->pluck('group_name')->join(', ') . '.',
            ];
        }

        $stats = [
            'included' => $rows->count(),
            'ready' => $rows->where('status', 'ready')->count(),
            'warning' => $rows->where('status', 'warning')->count(),
            'blocked' => $rows->where('status', 'blocked')->count(),
            'unassigned' => $unassigned->count(),
        ];

        $hasPeriodBlocker = collect($periodIssues)->contains('severity', 'blocker');

        return response()->json([
            'success' => true,
            'message' => 'Payroll readiness checked successfully.',
            'data' => [
                'period' => [
                    'id' => $period->id,
                    'period_name' => $period->period_name,
                    'cutoff_number' => (int) $period->cutoff_number,
                    'cutoff_start' => $start,
                    'cutoff_end' => $end,
                    'payment_date' => $period->payment_date?->format('Y-m-d'),
                    'status' => $period->status,
                ],
                'period_issues' => $periodIssues,
                'stats' => $stats,
                'groups' => $groups,
                'employees' => $rows,
                'unassigned' => $unassigned,
                'can_generate' => !$hasPeriodBlocker
                    && $stats['blocked'] === 0
                    && $stats['included'] > 0,
            ],
        ]);
    }
}