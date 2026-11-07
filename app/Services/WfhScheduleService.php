<?php

namespace App\Services;

use App\Models\WorkFromHome;
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
        $days = $wfh->employee?->latestEmployment?->workSchedule?->days ?? collect();

        return $days
            ->where('is_working_day', true)
            ->map(fn ($day) => self::DAY_MAP[strtolower(substr(trim((string) $day->day_of_week), 0, 3))] ?? null)
            ->filter()
            ->unique()
            ->values()
            ->all();
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
}