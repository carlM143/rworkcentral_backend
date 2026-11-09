<?php

namespace App\Services;

use App\Models\WorkFromHome;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class WfhScheduleService
{
    /**
     * Accepts both full names ("Saturday") and the short values
     * currently stored in work_schedule_days ("Sat", "Sun").
     */
    private const DAY_MAP = [
        'mon' => 'Monday',
        'tue' => 'Tuesday',
        'wed' => 'Wednesday',
        'thu' => 'Thursday',
        'fri' => 'Friday',
        'sat' => 'Saturday',
        'sun' => 'Sunday',
    ];

    /**
     * Working days (full day names) of the employee's current schedule.
     * Expects employee.latestEmployment.workSchedule.days to be loaded.
     *
     * @return array<int, string>
     */
    public function workingDayNames(WorkFromHome $wfh): array
    {
        $schedule = $this->getWorkSchedule($wfh);
        $days = $schedule?->days ?? collect();

        return $days
            ->where('is_working_day', true)
            ->map(fn ($day) => self::DAY_MAP[strtolower(substr(trim((string) $day->day_of_week), 0, 3))] ?? null)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function getWorkSchedule(WorkFromHome $wfh): ?WorkSchedule
    {
        $employee = $wfh->employee;
        if (! $employee) {
            return null;
        }

        $schedule = $employee->latestEmployment?->workSchedule;
        if ($schedule) {
            return $schedule;
        }

        if ($employee->relationLoaded('employments')) {
            $current = $employee->employments->sortByDesc('date_hired')->first();
            if ($current?->workSchedule) {
                return $current->workSchedule;
            }
        }

        return null;
    }

    public function hasSchedule(WorkFromHome $wfh): bool
    {
        return $this->workingDayNames($wfh) !== [];
    }

    /**
     * Scheduled WFH dates (Y-m-d) between start_date and end_date.
     * If the employee has no schedule yet, every calendar day counts.
     *
     * @return array<int, string>
     */
    public function workingDates(WorkFromHome $wfh): array
    {
        if (! $wfh->start_date || ! $wfh->end_date) {
            return [];
        }

        $working = $this->workingDayNames($wfh);
        $dates = [];

        foreach (CarbonPeriod::create($wfh->start_date, $wfh->end_date) as $date) {
            if ($working === [] || in_array($date->format('l'), $working, true)) {
                $dates[] = $date->toDateString();
            }
        }

        return $dates;
    }

    /**
     * Complete breakdown of dates in range checked against employee's schedule.
     *
     * @return array{
     *     number_of_days: int,
     *     calendar_days: int,
     *     working_dates: array<int, string>,
     *     breakdown: array<int, array{date: string, day_name: string, is_working_day: bool}>,
     *     schedule_name: ?string,
     *     schedule_code: ?string,
     *     working_days: array<int, string>,
     *     rest_days: array<int, string>
     * }
     */
    public function scheduleBreakdown(WorkFromHome $wfh): array
    {
        $schedule = $this->getWorkSchedule($wfh);
        $scheduleDays = $schedule?->days ?? collect();
        $workingDayNames = $this->workingDayNames($wfh);
        $allWeekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $restDayNames = $workingDayNames === []
            ? []
            : array_values(array_diff($allWeekdays, $workingDayNames));

        $dayTimes = [];
        foreach ($scheduleDays as $day) {
            $normalizedName = self::DAY_MAP[strtolower(substr(trim((string) $day->day_of_week), 0, 3))] ?? null;
            if ($normalizedName && $day->is_working_day) {
                $timeIn = $day->time_in ? Carbon::parse($day->time_in)->format('g:i A') : null;
                $timeOut = $day->time_out ? Carbon::parse($day->time_out)->format('g:i A') : null;
                $dayTimes[$normalizedName] = ($timeIn && $timeOut) ? "{$timeIn} – {$timeOut}" : 'Regular Shift';
            }
        }

        $workingDates = [];
        $breakdown = [];
        $totalCalendarDays = 0;

        if ($wfh->start_date && $wfh->end_date) {
            foreach (CarbonPeriod::create($wfh->start_date, $wfh->end_date) as $date) {
                $totalCalendarDays++;
                $dayName = $date->format('l');
                $dateStr = $date->toDateString();
                $isWorking = $workingDayNames === [] || in_array($dayName, $workingDayNames, true);

                if ($isWorking) {
                    $workingDates[] = $dateStr;
                }

                $breakdown[] = [
                    'date' => $dateStr,
                    'day_name' => $dayName,
                    'is_working_day' => $isWorking,
                    'schedule_time' => $isWorking ? ($dayTimes[$dayName] ?? 'Regular Shift') : null,
                ];
            }
        }

        return [
            'number_of_days' => count($workingDates),
            'calendar_days' => $totalCalendarDays,
            'working_dates' => $workingDates,
            'breakdown' => $breakdown,
            'schedule_name' => $schedule?->schedule_name ?? $schedule?->schedule_code ?? null,
            'schedule_code' => $schedule?->schedule_code ?? null,
            'working_days' => $workingDayNames,
            'rest_days' => $restDayNames,
        ];
    }
}