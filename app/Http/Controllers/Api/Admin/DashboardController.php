<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\TimeLog;
use App\Models\WorkFromHome;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    // GET /admin/dashboard/summary?date=YYYY-MM-DD (date is optional, defaults to today)
    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $date = Carbon::parse($validated['date'] ?? now()->toDateString())->startOfDay();
        $day = $date->toDateString();

        // Active employees only
        $activeIds = Employee::query()
            ->where('status', true)
            ->pluck('id');

        $total = $activeIds->count();

        $newThisMonth = Employee::query()
            ->where('status', true)
            ->whereBetween('created_at', [
                $date->copy()->startOfMonth(),
                $date->copy()->endOfMonth(),
            ])
            ->count();

        // Present + Late (from time logs)
        $logs = TimeLog::query()
            ->whereDate('work_date', $day)
            ->whereNotNull('time_in')
            ->whereIn('employee_id', $activeIds)
            ->get(['employee_id', 'late_minutes']);

        $presentIds = $logs->pluck('employee_id')->unique();

        $lateIds = $logs
            ->filter(fn ($log) => (int) $log->late_minutes > 0)
            ->pluck('employee_id')
            ->unique();

        // Approved WFH covering the date
        $wfhIds = WorkFromHome::query()
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $day)
            ->whereDate('end_date', '>=', $day)
            ->whereIn('employee_id', $activeIds)
            ->pluck('employee_id')
            ->unique();

        // Approved leave covering the date
        $leaveIds = LeaveRequest::query()
            ->where('status', 'approved')
            ->whereHas('leaveDates', fn ($q) => $q->whereDate('leave_date', $day))
            ->whereIn('employee_id', $activeIds)
            ->pluck('employee_id')
            ->unique();

        // Absent: no time-in, not on leave, not on approved WFH. Never for future dates.
        $absentCount = $date->isFuture()
            ? 0
            : $activeIds
                ->diff($presentIds)
                ->diff($leaveIds)
                ->diff($wfhIds)
                ->count();

        $percent = fn (int $count): float => $total > 0
            ? round(($count / $total) * 100, 1)
            : 0.0;

        return response()->json([
            'success' => true,
            'message' => 'Dashboard summary retrieved successfully.',
            'data' => [
                'date' => $day,
                'total_employees' => [
                    'value' => $total,
                    'new_this_month' => $newThisMonth,
                ],
                'present' => [
                    'value' => $presentIds->count(),
                    'percent' => $percent($presentIds->count()),
                ],
                'late' => [
                    'value' => $lateIds->count(),
                    'percent' => $percent($lateIds->count()),
                ],
                'absent' => [
                    'value' => $absentCount,
                    'percent' => $percent($absentCount),
                ],
                'wfh' => [
                    'value' => $wfhIds->count(),
                    'percent' => $percent($wfhIds->count()),
                ],
            ],
        ]);
    }

    // =====================================================
    // ATTENDANCE OVERVIEW (chart)
    // =====================================================

    /**
     * GET /admin/dashboard/attendance?range=this_week|last_week|last_14_days
     *
     * Per day: present (timed in), late (timed in late), absent, on leave,
     * WFH and scheduled. Same rules as summary(), plus:
     *   - only employees scheduled to work that day count (rest days skipped)
     *   - holidays and future days have no absences
     */
    public function attendance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'range' => ['nullable', 'in:this_week,last_week,last_14_days'],
        ]);

        $range = $validated['range'] ?? 'this_week';
        $today = today();

        [$from, $to] = match ($range) {
            'last_week' => [$today->copy()->subWeek()->startOfWeek(), $today->copy()->subWeek()->endOfWeek()->startOfDay()],
            'last_14_days' => [$today->copy()->subDays(13), $today->copy()],
            default => [$today->copy()->startOfWeek(), $today->copy()->endOfWeek()->startOfDay()],
        };

        $activeIds = Employee::query()->where('status', true)->pluck('id');
        $workingDays = $this->workingDaysByEmployee($activeIds);
        $holidays = $this->holidayDates($from, $to);

        // Time logs in the range, keyed by date.
        $logs = TimeLog::query()
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('time_in')
            ->whereIn('employee_id', $activeIds)
            ->get(['employee_id', 'work_date', 'late_minutes'])
            ->groupBy(fn ($log) => Carbon::parse($log->work_date)->toDateString());

        // Approved leave days in the range, keyed by date.
        $leaves = DB::table('leave_request_dates')
            ->join('leave_requests', 'leave_requests.id', '=', 'leave_request_dates.leave_request_id')
            ->where('leave_requests.status', 'approved')
            ->whereIn('leave_requests.employee_id', $activeIds)
            ->whereBetween('leave_request_dates.leave_date', [$from->toDateString(), $to->toDateString()])
            ->get(['leave_requests.employee_id', 'leave_request_dates.leave_date'])
            ->groupBy(fn ($row) => Carbon::parse($row->leave_date)->toDateString());

        // Approved WFH overlapping the range.
        $wfh = WorkFromHome::query()
            ->where('status', 'approved')
            ->whereIn('employee_id', $activeIds)
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->get(['employee_id', 'start_date', 'end_date']);

        $days = [];

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $key = $date->toDateString();
            $dow = strtolower($date->format('D')); // mon..sun

            $scheduled = $activeIds->filter(fn ($id) => in_array($dow, $workingDays[$id] ?? ['mon', 'tue', 'wed', 'thu', 'fri'], true));

            $dayLogs = $logs->get($key, collect());
            $present = $dayLogs->pluck('employee_id')->unique();
            $late = $dayLogs->filter(fn ($l) => (int) $l->late_minutes > 0)->pluck('employee_id')->unique();
            $onLeave = $leaves->get($key, collect())->pluck('employee_id')->unique();
            $onWfh = $wfh
                ->filter(fn ($w) => Carbon::parse($w->start_date)->lte($date) && Carbon::parse($w->end_date)->gte($date))
                ->pluck('employee_id')
                ->unique();

            $isFuture = $date->gt($today);
            $isHoliday = isset($holidays[$key]);

            $absent = ($isFuture || $isHoliday)
                ? 0
                : $scheduled->diff($present)->diff($onLeave)->diff($onWfh)->count();

            $days[] = [
                'date' => $key,
                'label' => $range === 'last_14_days' ? $date->format('M j') : $date->format('D'),
                'scheduled' => $scheduled->count(),
                'present' => $isFuture ? null : $present->count(),
                'late' => $isFuture ? null : $late->count(),
                'absent' => $isFuture ? null : $absent,
                'on_leave' => $onLeave->count(),
                'wfh' => $onWfh->count(),
                'is_future' => $isFuture,
                'is_holiday' => $isHoliday,
                'holiday_name' => $holidays[$key] ?? null,
            ];
        }

        $past = collect($days)->where('is_future', false)->where('is_holiday', false);
        $scheduledTotal = $past->sum('scheduled');

        return response()->json([
            'success' => true,
            'message' => 'Attendance overview retrieved.',
            'data' => [
                'range' => $range,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'days' => $days,
                'summary' => [
                    'attendance_rate' => $scheduledTotal > 0
                        ? round(($past->sum('present') + $past->sum('wfh')) / $scheduledTotal * 100, 1)
                        : null,
                    'late_total' => $past->sum('late'),
                    'absent_total' => $past->sum('absent'),
                ],
            ],
        ]);
    }

    // =====================================================
    // DEPARTMENT HEADCOUNT (donut)
    // =====================================================

    /**
     * GET /admin/dashboard/headcount?top=5
     * Active employees per department; the rest are grouped as "Others".
     */
    public function headcount(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'top' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        $top = (int) ($validated['top'] ?? 5);

        $rows = DB::table('employees')
            ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
            ->where('employees.status', true)
            ->groupBy('employees.department_id', 'departments.department_name')
            ->selectRaw('employees.department_id AS id, departments.department_name AS name, COUNT(*) AS total')
            ->orderByDesc('total')
            ->get();

        $total = (int) $rows->sum('total');
        $percent = fn (int $n) => $total > 0 ? round($n / $total * 100, 1) : 0.0;

        $departments = $rows->take($top)->map(fn ($r) => [
            'id' => $r->id ? (int) $r->id : null,
            'name' => $r->name ?? 'No department',
            'count' => (int) $r->total,
            'percent' => $percent((int) $r->total),
        ])->values();

        $others = (int) $rows->slice($top)->sum('total');

        if ($others > 0) {
            $departments->push([
                'id' => null,
                'name' => 'Others',
                'count' => $others,
                'percent' => $percent($others),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Department headcount retrieved.',
            'data' => [
                'total' => $total,
                'department_count' => $rows->count(),
                'departments' => $departments,
            ],
        ]);
    }

    // =====================================================
    // HELPERS
    // =====================================================

    /**
     * Working days (mon..sun) per employee, from the work schedule of their
     * latest employment. Employees without a schedule are left out and
     * default to Monday–Friday.
     *
     * @return array<int, array<int, string>>
     */
    private function workingDaysByEmployee(Collection $employeeIds): array
    {
        $rows = DB::table('employments')
            ->join('work_schedule_days', 'work_schedule_days.work_schedule_id', '=', 'employments.work_schedule_id')
            ->whereIn('employments.employee_id', $employeeIds)
            ->whereRaw('employments.id = (SELECT MAX(e2.id) FROM employments e2 WHERE e2.employee_id = employments.employee_id)')
            ->where('work_schedule_days.is_working_day', true)
            ->get(['employments.employee_id', 'work_schedule_days.day_of_week']);

        $map = [];

        foreach ($rows as $row) {
            $map[$row->employee_id][] = strtolower(substr(trim((string) $row->day_of_week), 0, 3));
        }

        return $map;
    }

    /**
     * Active holidays in the range: date => holiday name.
     *
     * @return array<string, string>
     */
    private function holidayDates(Carbon $from, Carbon $to): array
    {
        if (! Schema::hasTable('holidays')) {
            return [];
        }

        $range = [$from->toDateString(), $to->toDateString()];

        $dates = DB::table('holidays')
            ->where('status', true)
            ->whereBetween('holiday_date', $range)
            ->pluck('holiday_name', 'holiday_date')
            ->all();

        if (Schema::hasTable('holiday_dates')) {
            DB::table('holiday_dates')
                ->join('holidays', 'holidays.id', '=', 'holiday_dates.holiday_id')
                ->where('holidays.status', true)
                ->whereBetween('holiday_dates.holiday_date', $range)
                ->get(['holiday_dates.holiday_date', 'holidays.holiday_name'])
                ->each(function ($row) use (&$dates) {
                    $dates[Carbon::parse($row->holiday_date)->toDateString()] = $row->holiday_name;
                });
        }

        return collect($dates)->mapWithKeys(fn ($name, $date) => [Carbon::parse($date)->toDateString() => $name])->all();
    }
}