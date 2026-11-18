<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeOffboarding;
use App\Models\Holiday;
use App\Models\LeaveRequestDate;
use App\Models\OvertimeRequest;
use App\Models\RestDaySwitchRequest;
use App\Models\TimeLog;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

class AttendanceReportService
{
    public const METRICS = ['scheduledDays', 'presentDays', 'absentDays', 'leaveDays', 'lateCount', 'lateMinutes', 'undertimeMinutes', 'workedMinutes', 'overtimeMinutes', 'missingPunchDays'];

    public function build(array $filters): array
    {
        $start = $filters['start'];
        $end = $filters['end'];
        $employees = Employee::with([
            'department', 'latestEmployment.department', 'latestEmployment.location', 'latestEmployment.position',
            'employments.department', 'employments.location', 'employments.workSchedule.days',
            'employments.workSchedule.revisions.days',
        ])->orderBy('lastname')->orderBy('firstname')->orderBy('id')->get();

        $options = [
            'departments' => $employees->map(fn ($e) => $e->latestEmployment?->department ?? $e->department)
                ->filter()->unique('id')->map(fn ($d) => ['id' => $d->id, 'name' => $d->department_name])->values(),
            'locations' => $employees->map(fn ($e) => $e->latestEmployment?->location)
                ->filter()->unique('id')->map(fn ($l) => ['id' => $l->id, 'name' => $l->location_name])->values(),
        ];
        $employees = $employees->filter(function ($e) use ($filters) {
            $employment = $e->latestEmployment;
            $name = mb_strtolower(implode(' ', array_filter([$e->emp_id, $e->firstname, $e->middlename, $e->lastname, $e->suffix])));
            return (!($filters['search'] ?? '') || str_contains($name, mb_strtolower($filters['search'])))
                && (!($filters['employee_id'] ?? null) || $e->id === (int) $filters['employee_id'])
                && (!($filters['department_id'] ?? null) || ($employment?->department_id ?? $e->department_id) == $filters['department_id'])
                && (!($filters['location_id'] ?? null) || $employment?->location_id == $filters['location_id'])
                && (($filters['employee_status'] ?? 'all') === 'all' || $e->status === ($filters['employee_status'] === 'active'));
        });
        $ids = $employees->pluck('id');
        $logs = TimeLog::with('correctionRequests')->whereIn('employee_id', $ids)->whereDate('work_date', '>=', $start)->whereDate('work_date', '<=', $end)->orderBy('id')->get()->groupBy('employee_id');
        $leaves = LeaveRequestDate::with('leaveRequest')->whereHas('leaveRequest', fn ($q) => $q->whereIn('employee_id', $ids)->where('status', 'approved'))
            ->whereDate('leave_date', '>=', $start)->whereDate('leave_date', '<=', $end)->get()->groupBy(fn ($d) => $d->leaveRequest->employee_id);
        $overtime = OvertimeRequest::whereIn('employee_id', $ids)->where('status', 'approved')->whereDate('ot_date', '>=', $start)->whereDate('ot_date', '<=', $end)->get()->groupBy('employee_id');
        $switches = RestDaySwitchRequest::whereIn('employee_id', $ids)->where('status', 'approved')->get()->groupBy('employee_id');
        $exits = EmployeeOffboarding::whereIn('employee_id', $ids)->where('status', 'Completed')->orderByDesc('id')->get()->unique('employee_id')->keyBy('employee_id');
        $holidays = Holiday::with(['locations', 'coveredEmployees', 'holidayDates'])->where('status', true)
            ->where(fn ($q) => $q->where(fn ($d) => $d->whereDate('holiday_date', '>=', $start)->whereDate('holiday_date', '<=', $end))
                ->orWhereHas('holidayDates', fn ($d) => $d->whereDate('holiday_date', '>=', $start)->whereDate('holiday_date', '<=', $end)))->get();
        $holidaysByDate = [];
        foreach ($holidays as $holiday) {
            $dates = $holiday->holidayDates->isNotEmpty() ? $holiday->holidayDates->pluck('holiday_date') : collect([$holiday->holiday_date]);
            foreach ($dates as $date) {
                $holidaysByDate[$date->format('Y-m-d')][] = $holiday;
            }
        }

        $rows = [];
        foreach ($employees as $employee) {
            $employeeLogs = $logs->get($employee->id, collect())->groupBy(fn ($l) => $l->work_date->format('Y-m-d'));
            $employeeLeaves = $leaves->get($employee->id, collect())->groupBy(fn ($l) => $l->leave_date->format('Y-m-d'));
            $employeeOvertime = $overtime->get($employee->id, collect())->groupBy(fn ($o) => $o->ot_date->format('Y-m-d'));
            $days = [];
            $summary = array_fill_keys(self::METRICS, 0);
            foreach (CarbonPeriod::create($start, $end) as $day) {
                $date = $day->format('Y-m-d');
                $employment = $employee->employments->filter(fn ($e) => $e->date_hired?->format('Y-m-d') <= $date)
                    ->sortByDesc(fn ($e) => $e->date_hired->format('Y-m-d').sprintf('%012d', $e->id))->first();
                $dayLogs = $employeeLogs->get($date, collect());
                $schedule = $this->schedule($employment?->workSchedule, $date);
                foreach ($switches->get($employee->id, collect()) as $switch) {
                    if ($switch->effective_date?->format('Y-m-d') === $date) $schedule['working'] = false;
                    if ($switch->original_rest_date?->format('Y-m-d') === $date) $schedule['working'] = true;
                }
                $snapshot = $dayLogs->first(fn ($l) => $l->scheduled_time_in && $l->scheduled_time_out);
                if ($snapshot) {
                    $schedule = ['working' => true, 'in' => $snapshot->scheduled_time_in->format('Y-m-d H:i:s'), 'out' => $snapshot->scheduled_time_out->format('Y-m-d H:i:s')];
                }
                $covered = collect($holidaysByDate[$date] ?? [])->filter(function ($h) use ($employee, $employment) {
                    $companyId = $employment?->department?->company_id ?? $employee->department?->company_id;
                    if ($h->company_id != $companyId) return false;
                    if ($h->applies_to !== 'all_locations' && !$h->locations->contains('id', $employment?->location_id)) return false;
                    $selected = $h->coveredEmployees->contains('id', $employee->id);
                    return match ($h->employee_coverage ?? 'all') { 'only' => $selected, 'except' => !$selected, default => true };
                });
                $nonWorkingHoliday = $covered->contains(fn ($h) => !$h->is_working_day);
                $exit = $exits->get($employee->id)?->details;
                $lastDay = $exit['lastWorkingDay'] ?? null;
                $outsideEmployment = !$employment || ($lastDay && $date > $lastDay);
                $working = !$outsideEmployment && $schedule['working'] === true && !$nonWorkingHoliday;
                $present = $dayLogs->contains(fn ($l) => $l->time_in !== null);
                $missing = $dayLogs->contains(fn ($l) => ($l->time_in === null) !== ($l->time_out === null));
                $leaveValue = min(1, (float) $employeeLeaves->get($date, collect())->sum('day_value'));
                $isPast = $date < now()->toDateString();
                $shiftEnded = !$schedule['out'] || CarbonImmutable::parse($schedule['out'])->isPast();
                // Unknown inactive end dates and unknown schedules cannot establish an absence.
                $canInferAbsence = $employee->status || (bool) $lastDay;
                $absent = $working && !$present && $isPast && $shiftEnded && $canInferAbsence ? max(0, 1 - $leaveValue) : 0;
                $status = match (true) {
                    $missing => 'Missing punch',
                    $present => 'Present',
                    $employee->employments->isEmpty() => 'No employment record',
                    $outsideEmployment => 'Outside employment',
                    $nonWorkingHoliday => 'Holiday',
                    $leaveValue >= 1 => 'On leave',
                    $absent > 0 && $leaveValue > 0 => 'Partial leave / absent',
                    $absent > 0 => 'Absent',
                    $leaveValue > 0 => 'Partial leave',
                    $schedule['working'] === false => 'Rest day',
                    $schedule['working'] === null => 'No schedule',
                    !$canInferAbsence => 'Inactive — verify dates',
                    default => 'Scheduled',
                };
                $late = (int) $dayLogs->sum(fn ($l) => $l->chargeable_late_minutes ?? $l->late_minutes ?? 0);
                $worked = (int) $dayLogs->filter(fn ($l) => $l->time_in && $l->time_out)->sum('worked_minutes');
                $ot = (int) $employeeOvertime->get($date, collect())->sum(fn ($o) => $o->start_at && $o->end_at ? max(0, $o->start_at->diffInMinutes($o->end_at, false)) : 0);
                $undertime = (int) $dayLogs->sum('undertime_minutes');
                $values = ['scheduledDays' => (int) $working, 'presentDays' => (int) $present, 'absentDays' => $absent,
                    'leaveDays' => $outsideEmployment ? 0 : $leaveValue, 'lateCount' => (int) ($late > 0), 'lateMinutes' => $late,
                    'undertimeMinutes' => $undertime, 'workedMinutes' => $worked, 'overtimeMinutes' => $ot, 'missingPunchDays' => (int) $missing];
                foreach (self::METRICS as $key) $summary[$key] += $values[$key];
                $days[] = [
                    'date' => $date, 'status' => $status, 'scheduledIn' => $schedule['in'], 'scheduledOut' => $schedule['out'],
                    'timeIn' => $dayLogs->pluck('time_in')->filter()->sort()->first()?->format('Y-m-d H:i:s'),
                    'timeOut' => $dayLogs->pluck('time_out')->filter()->sort()->last()?->format('Y-m-d H:i:s'),
                    'source' => $dayLogs->pluck('source')->filter()->unique()->implode(', '),
                    'correction' => $dayLogs->flatMap(fn ($l) => $l->correctionRequests)->pluck('status')->unique()->implode(', '),
                    'holiday' => $covered->pluck('holiday_name')->implode(', '), 'leaveDays' => $leaveValue,
                    'lateMinutes' => $late, 'undertimeMinutes' => $undertime, 'workedMinutes' => $worked, 'overtimeMinutes' => $ot,
                    'remarks' => $dayLogs->pluck('remarks')->filter()->implode('; '),
                ];
            }
            $condition = $filters['condition'] ?? '';
            if (($condition === 'absent' && !$summary['absentDays']) || ($condition === 'late' && !$summary['lateCount'])
                || ($condition === 'missing' && !$summary['missingPunchDays']) || ($condition === 'no_logs' && $employeeLogs->isNotEmpty())) continue;
            $rows[] = [
                'id' => $employee->id, 'employeeNumber' => (string) $employee->emp_id,
                'employeeName' => implode(' ', array_filter([$employee->firstname, $employee->middlename, $employee->lastname, $employee->suffix])),
                'department' => $employee->latestEmployment?->department?->department_name ?? $employee->department?->department_name ?? 'Unassigned',
                'location' => $employee->latestEmployment?->location?->location_name ?? 'Unassigned',
                'position' => $employee->latestEmployment?->position?->position ?? 'Unassigned',
                'active' => $employee->status, 'hasLogs' => $employeeLogs->isNotEmpty(), ...$summary,
                ...(!empty($filters['details']) ? ['days' => $days] : []),
            ];
        }
        $totals = ['employees' => count($rows)];
        foreach (self::METRICS as $key) $totals[$key] = collect($rows)->sum($key);
        return ['data' => $rows, 'totals' => $totals, 'options' => $options,
            'period' => ['start' => $start, 'end' => $end], 'generatedAt' => now()->toIso8601String()];
    }

    private function schedule($schedule, string $date): array
    {
        $empty = ['working' => null, 'in' => null, 'out' => null];
        if (!$schedule) return $empty;
        $revision = $schedule->revisions->filter(fn ($r) => $r->effective_from->format('Y-m-d') <= $date
            && (!$r->effective_to || $r->effective_to->format('Y-m-d') >= $date))->sortByDesc('effective_from')->first();
        if (!$revision && $schedule->revisions->isNotEmpty()) return $empty;
        $days = $revision?->days ?? $schedule->days;
        if ($days->isEmpty()) return $empty;
        $prefix = strtolower(CarbonImmutable::parse($date)->format('D'));
        $day = $days->first(fn ($d) => strtolower(substr($d->day_of_week, 0, 3)) === $prefix);
        if (!$day || ($day->is_working_day !== null && !$day->is_working_day)) return [...$empty, 'working' => false];
        $in = $day->time_in ? CarbonImmutable::parse($date.' '.$day->time_in) : null;
        $out = $day->time_out ? CarbonImmutable::parse($date.' '.$day->time_out) : null;
        if ($in && $out && $out <= $in) $out = $out->addDay();
        return ['working' => true, 'in' => $in?->format('Y-m-d H:i:s'), 'out' => $out?->format('Y-m-d H:i:s')];
    }
}
