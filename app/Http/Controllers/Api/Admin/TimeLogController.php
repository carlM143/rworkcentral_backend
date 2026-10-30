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
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'department_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'max:50'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $limit = $validated['limit'] ?? 500;

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

            $query->whereHas('employee', function ($q) use ($departmentId) {
                $q->where('department_id', $departmentId);
            });
        }

        if (!empty($validated['search'])) {
            $search = $validated['search'];

            $query->whereHas('employee', function ($q) use ($search) {
                $q->where(function ($employeeQuery) use ($search) {
                    $employeeQuery
                        ->where('firstname', 'like', "%{$search}%")
                        ->orWhere('lastname', 'like', "%{$search}%")
                        ->orWhere('emp_id', 'like', "%{$search}%");
                });
            });
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

        $data = $logs->map(function (TimeLog $log) use ($departmentNames) {
            return $this->transformLog($log, $departmentNames);
        });

        $stats = [
            'total_logs' => $data->count(),
            'complete_logs' => $data->where('status', 'Complete')->count(),
            'missing_logs' => $data->where('status', 'Missing')->count(),
            'with_issues' => $data->filter(function ($row) {
                return in_array($row['status'], [
                    'Late',
                    'Undertime',
                    'Incomplete',
                ], true);
            })->count(),
        ];

        return response()->json([
            'success' => true,
            'message' => 'Time logs retrieved successfully.',
            'data' => $data->values(),
            'stats' => $stats,
            'meta' => [
                'count' => $data->count(),
                'limit' => $limit,
            ],
        ]);
    }

    public function show(TimeLog $timeLog): JsonResponse
    {
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

        $lateMinutes = (int) ($log->late_minutes ?? 0);
        $undertimeMinutes = (int) ($log->undertime_minutes ?? 0);
        $workedMinutes = $log->worked_minutes;

        return [
            'id' => $log->id,
            'employee_id' => $log->employee_id,
            'employee_number' => $employee?->emp_id ?? '',
            'employee_name' => $employeeName ?: 'Unknown Employee',

            'department_id' => $departmentId,
            'department' => $departmentNames[$departmentId]
                ?? 'Unassigned',

            'work_date' => $log->work_date
                ? Carbon::parse($log->work_date)->format('Y-m-d')
                : null,

            // Temporary schedule display for dummy records.
            'schedule' => '8:00 AM - 5:00 PM',

            'time_in' => $this->formatTime($log->time_in),
            'time_out' => $this->formatTime($log->time_out),
            'break_out' => $this->formatTime($log->break_out),
            'break_in' => $this->formatTime($log->break_in),

            'late_minutes' => $lateMinutes,
            'undertime_minutes' => $undertimeMinutes,
            'worked_minutes' => $workedMinutes !== null
                ? (int) $workedMinutes
                : null,

            'worked_hours' => $workedMinutes !== null
                ? round((int) $workedMinutes / 60, 2)
                : null,

            'overtime_minutes' => (int) ($log->overtime_minutes ?? 0),

            'status' => $this->resolveStatus($log),
            'source' => $log->source,
            'remarks' => $log->remarks,
            'created_at' => $log->created_at?->toDateTimeString(),
            'updated_at' => $log->updated_at?->toDateTimeString(),
        ];
    }

    private function formatTime($value): ?string
    {
        if (!$value) {
            return null;
        }

        return Carbon::parse($value)->format('h:i A');
    }

    private function resolveStatus(TimeLog $log): string
    {
        $status = strtolower(
            trim((string) $log->status)
        );

        if ($status === 'missing' || $status === 'absent') {
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

        if ($status === 'complete' || $status === 'completed') {
            return 'Complete';
        }

        if (!$log->time_in && !$log->time_out) {
            return 'Missing';
        }

        if (!$log->time_in || !$log->time_out) {
            return 'Incomplete';
        }

        if ((int) $log->late_minutes > 0) {
            return 'Late';
        }

        if ((int) $log->undertime_minutes > 0) {
            return 'Undertime';
        }

        return 'Complete';
    }
}
