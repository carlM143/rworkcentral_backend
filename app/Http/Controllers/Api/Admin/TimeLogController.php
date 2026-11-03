<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TimeLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TimeLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeTimekeeping($request, 'view');

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'department_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'max:50'],
            'date_from' => ['nullable', 'date'],
            'date_to' => [
                'nullable',
                'date',
                'after_or_equal:date_from',
            ],
            'limit' => [
                'nullable',
                'integer',
                'min:1',
                'max:1000',
            ],
        ]);

        $limit = (int) ($validated['limit'] ?? 500);

        $query = TimeLog::query()
            ->with('employee')
            ->orderByDesc('work_date')
            ->orderBy('employee_id');

        if (!empty($validated['date_from'])) {
            $query->whereDate(
                'work_date',
                '>=',
                $validated['date_from']
            );
        }

        if (!empty($validated['date_to'])) {
            $query->whereDate(
                'work_date',
                '<=',
                $validated['date_to']
            );
        }

        if (!empty($validated['department_id'])) {
            $departmentId = $validated['department_id'];

            $query->whereHas(
                'employee',
                function ($q) use ($departmentId) {
                    $q->where(
                        'department_id',
                        $departmentId
                    );
                }
            );
        }

        if (!empty($validated['search'])) {
            $search = $validated['search'];

            $query->whereHas(
                'employee',
                function ($q) use ($search) {
                    $q->where(
                        function ($employeeQuery) use ($search) {
                            $employeeQuery
                                ->where(
                                    'firstname',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'lastname',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'emp_id',
                                    'like',
                                    "%{$search}%"
                                );
                        }
                    );
                }
            );
        }

        if (!empty($validated['status'])) {
            $query->where(
                'status',
                $validated['status']
            );
        }

        $logs = $query->limit($limit)->get();

        $departmentNames = DB::table('departments')
            ->pluck('department_name', 'id')
            ->all();

        $data = $logs->map(
            fn (TimeLog $log) => $this->transformLog(
                $log,
                $departmentNames
            )
        )->values();

        $stats = [
            'total_logs' => $data->count(),

            'complete_logs' => $data->filter(
                fn ($row) => $row['attendance_status'] === 'Complete'
            )->count(),

            'missing_logs' => $data->filter(
                fn ($row) => $row['attendance_status'] === 'Missing'
            )->count(),

            'with_issues' => $data->filter(
                fn ($row) => $row['has_issues']
            )->count(),
        ];

        return response()->json([
            'success' => true,
            'message' => 'Time logs retrieved successfully.',
            'data' => $data,
            'stats' => $stats,
            'meta' => [
                'count' => $data->count(),
                'limit' => $limit,
            ],
        ]);
    }

    public function show( Request $request, TimeLog $timeLog): JsonResponse {
    $this->authorizeTimekeeping($request, 'view');

    $timeLog->load('employee');

        $departmentNames = DB::table('departments')
            ->pluck('department_name', 'id')
            ->all();

        return response()->json([
            'success' => true,
            'message' => 'Time log details retrieved successfully.',
            'data' => $this->transformLog(
                $timeLog,
                $departmentNames
            ),
        ]);
    }

    private function transformLog(
        TimeLog $log,
        array $departmentNames
    ): array {
        $employee = $log->employee;

        $employeeName = trim(
            ($employee?->firstname ?? '') . ' ' .
            ($employee?->lastname ?? '')
        );

        $departmentId = $employee?->department_id;

        // Historical records may not have schedule snapshots.
        $rawLateMinutes = $log->raw_late_minutes !== null
            ? (int) $log->raw_late_minutes
            : (int) ($log->late_minutes ?? 0);

        $graceMinutes = $log->grace_period_minutes !== null
            ? (int) $log->grace_period_minutes
            : null;

        $chargeableLateMinutes =
            $log->chargeable_late_minutes !== null
                ? (int) $log->chargeable_late_minutes
                : null;

        // Preserve original values for historical attendance.
        $effectiveLateMinutes = $chargeableLateMinutes
            ?? $rawLateMinutes;

        $undertimeMinutes = (int) (
            $log->undertime_minutes ?? 0
        );

        $workedMinutes = $log->worked_minutes !== null
            ? (int) $log->worked_minutes
            : null;

        $attendanceStatus = $this->attendanceStatus($log);

        $hasIssues = in_array(
            $attendanceStatus,
            ['Missing', 'Incomplete'],
            true
        ) ||
            $effectiveLateMinutes > 0 ||
            $undertimeMinutes > 0;

        return [
            'id' => $log->id,
            'employee_id' => $log->employee_id,

            'employee_number' =>
                $employee?->emp_id ?? '',

            'employee_name' =>
                $employeeName ?: 'Unknown Employee',

            'department_id' => $departmentId,

            'department' =>
                $departmentNames[$departmentId]
                    ?? 'Unassigned',

            'work_date' => $log->work_date
                ? Carbon::parse(
                    $log->work_date
                )->format('Y-m-d')
                : null,

            // Actual schedule snapshot saved during import.
            'work_schedule_id' =>
                $log->work_schedule_id,

            'scheduled_time_in' =>
                $this->formatTime(
                    $log->scheduled_time_in
                ),

            'scheduled_time_out' =>
                $this->formatTime(
                    $log->scheduled_time_out
                ),

            'schedule' => $this->formatSchedule($log),

            'time_in' =>
                $this->formatTime($log->time_in),

            'time_out' =>
                $this->formatTime($log->time_out),

            'break_out' =>
                $this->formatTime($log->break_out),

            'break_in' =>
                $this->formatTime($log->break_in),

            // Legacy value remains available for compatibility.
            'late_minutes' => $rawLateMinutes,

            'raw_late_minutes' => $rawLateMinutes,

            'grace_period_minutes' => $graceMinutes,

            'chargeable_late_minutes' =>
                $chargeableLateMinutes,

            // The value shown in the Late column.
            'effective_late_minutes' =>
                $effectiveLateMinutes,

            'undertime_minutes' =>
                $undertimeMinutes,

            'worked_minutes' => $workedMinutes,

            'worked_hours' => $workedMinutes !== null
                ? round($workedMinutes / 60, 2)
                : null,

            'overtime_minutes' => (int) (
                $log->overtime_minutes ?? 0
            ),

            // Retain the original backend status.
            'status' => $this->resolveStatus($log),

            // Separate punch completeness from violations.
            'attendance_status' => $attendanceStatus,

            'has_issues' => $hasIssues,

            'source' => $log->source,
            'remarks' => $log->remarks,

            'created_at' =>
                $log->created_at?->toDateTimeString(),

            'updated_at' =>
                $log->updated_at?->toDateTimeString(),
        ];
    }

    private function formatTime($value): ?string
    {
        if (!$value) {
            return null;
        }

        return Carbon::parse($value)->format('h:i A');
    }

    private function formatSchedule(TimeLog $log): ?string
    {
        if (
            !$log->scheduled_time_in ||
            !$log->scheduled_time_out
        ) {
            // Do not invent historical schedule values.
            return null;
        }

        return $this->formatTime(
            $log->scheduled_time_in
        ) . ' - ' . $this->formatTime(
            $log->scheduled_time_out
        );
    }

    private function attendanceStatus(TimeLog $log): string
    {
        $status = strtolower(
            trim((string) $log->status)
        );

        if (in_array($status, ['on leave', 'leave'], true)) {
            return 'On Leave';
        }

        if ($status === 'rest day') {
            return 'Rest Day';
        }

        if (in_array($status, ['missing', 'absent'], true)) {
            return 'Missing';
        }

        if ($status === 'incomplete') {
            return 'Incomplete';
        }

        if (!$log->time_in && !$log->time_out) {
            return 'Missing';
        }

        if (!$log->time_in || !$log->time_out) {
            return 'Incomplete';
        }

        return 'Complete';
    }

    private function resolveStatus(TimeLog $log): string
    {
        $status = strtolower(
            trim((string) $log->status)
        );

        if (in_array($status, ['missing', 'absent'], true)) {
            return 'Missing';
        }

        if ($status === 'incomplete') {
            return 'Incomplete';
        }

        if ($status === 'late') {
            return 'Late';
        }

        if ($status === 'undertime') {
            return 'Undertime';
        }

        if (in_array($status, ['complete', 'completed'], true)) {
            return 'Complete';
        }

        if ($status === 'on leave' || $status === 'rest day') {
            return ucwords($status);
        }

        if (!$log->time_in && !$log->time_out) {
            return 'Missing';
        }

        if (!$log->time_in || !$log->time_out) {
            return 'Incomplete';
        }

        $effectiveLate = $log->chargeable_late_minutes
            ?? $log->late_minutes
            ?? 0;

        if ((int) $effectiveLate > 0) {
            return 'Late';
        }

        if ((int) $log->undertime_minutes > 0) {
            return 'Undertime';
        }

        return 'Complete';
    }

    private function authorizeTimekeeping(Request $request,string $privilege): void 
    {
        $user = $request->user();

        abort_unless(
            $user
            && in_array(
                $user->role?->name,
                ['Administrator', 'Human Resources'],
                true
            )
            && $user->hasPrivilege('timekeeping', $privilege),
            403,
            'You are not authorized to access this timekeeping resource.'
        );
    }
}
