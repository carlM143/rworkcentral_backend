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
}