<?php

namespace App\Services;

use App\Models\HolidayPayRule;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Holiday pay for payroll generation and payroll readiness.
 *
 * Who is paid comes from Holiday Coverage (holidays.employee_coverage):
 *  - all    : everyone in the holiday's locations
 *  - only   : only the selected employees, and only inside the holiday's locations
 *  - except : (legacy) everyone in the locations minus the selected employees
 *
 * A covered employee who did not work the holiday is paid for it:
 *  - rate = the "unworked" pay rule for the holiday type, or 100% when there is none
 *    (covering the employee on the Coverage page is the company's decision to pay)
 *  - daily-paid: the amount is added as holiday pay
 *  - monthly-paid: the monthly salary already includes the day, so nothing is added
 *
 * A covered employee who worked gets only the premium above what basic pay / overtime already pay:
 *  - worked holiday:    minute rate × worked minutes (max 8h) × (rate% − 100%)
 *  - rest day worked:   minute rate × worked minutes (max 8h) × rate%
 *  - holiday overtime:  minute rate × OT minutes × (OT rate% − 125%)
 */
class HolidayPayService
{
    private const MINUTES_PER_DAY = 480;
    private const REGULAR_OT_MULTIPLIER = 1.25;
    private const DEFAULT_UNWORKED_RATE = 100.0;

    // When two holidays fall on the same date, the higher-paying type wins.
    // Keys are lower-case; type codes are lower-cased before the lookup.
    private const TYPE_PRIORITY = [
        'double' => 4,
        'regular' => 3,
        'special_non_working' => 2,
        'special' => 2,
        'special_working' => 1,
        'company' => 1,
    ];

    /** Same salary type rule as PayrollGenerationService. */
    public static function rateType(?string $typeName): string
    {
        $name = strtolower((string) $typeName);

        if (str_contains($name, 'hour')) {
            return 'hourly';
        }

        if (str_contains($name, 'daily') || str_contains($name, 'day')) {
            return 'daily';
        }

        return 'monthly';
    }

    /**
     * Load everything needed for one payroll period in a few queries.
     */
    public function prepare(int $companyId, string $start, string $end, Collection $employeeIds): array
    {
        $dateColumn = Schema::hasColumn('holiday_dates', 'holiday_date') ? 'holiday_date' : 'holiday_dates';

        $holidayRows = DB::table('holiday_dates')
            ->join('holidays', 'holidays.id', '=', 'holiday_dates.holiday_id')
            ->join('holiday_types', 'holiday_types.id', '=', 'holidays.holiday_type_id')
            ->where('holidays.company_id', $companyId)
            ->where('holidays.status', true)
            ->whereBetween("holiday_dates.{$dateColumn}", [$start, $end])
            ->get([
                'holidays.id as holiday_id',
                'holidays.holiday_name',
                'holidays.holiday_type_id',
                'holidays.employee_coverage as policy',
                'holiday_types.type_code',
                'holiday_types.type_name',
                'holiday_types.requires_prior_day_present',
                "holiday_dates.{$dateColumn} as holiday_date",
            ]);

        if ($holidayRows->isEmpty()) {
            return ['holidays' => collect()];
        }

        $holidayIds = $holidayRows->pluck('holiday_id')->unique()->values();

        $locations = DB::table('holiday_locations')
            ->whereIn('holiday_id', $holidayIds)
            ->get(['holiday_id', 'location_id'])
            ->groupBy('holiday_id')
            ->map(fn ($rows) => $rows->pluck('location_id')->map(fn ($id) => (int) $id)->all());

        $employeeSelections = DB::table('holiday_employee_coverage')
            ->whereIn('holiday_id', $holidayIds)
            ->get(['holiday_id', 'employee_id'])
            ->groupBy('holiday_id')
            ->map(fn ($rows) => $rows->pluck('employee_id')->map(fn ($id) => (int) $id)->all());

        $holidays = $holidayRows
            ->map(function ($row) use ($locations, $employeeSelections) {
                $row->holiday_date = substr((string) $row->holiday_date, 0, 10);
                $row->policy = $row->policy ?: 'all';
                $row->location_ids = $locations->get($row->holiday_id, []);
                $row->selected_ids = $employeeSelections->get($row->holiday_id, []);

                return $row;
            })
            ->groupBy('holiday_date');

        // Latest employment per employee: location and work schedule
        $employments = DB::table('employments')
            ->whereIn('employee_id', $employeeIds)
            ->orderByDesc('id')
            ->get(['employee_id', 'location_id', 'work_schedule_id'])
            ->unique('employee_id')
            ->keyBy('employee_id');

        // Working weekdays per schedule
        $scheduleDays = DB::table('work_schedule_days')
            ->whereIn('work_schedule_id', $employments->pluck('work_schedule_id')->filter()->unique())
            ->get()
            ->groupBy('work_schedule_id')
            ->map(fn ($days) => $days
                ->filter(fn ($d) => (bool) ($d->is_working_day ?? true) && !empty($d->time_in ?? true))
                ->pluck('day_of_week')
                ->map(fn ($day) => strtolower((string) $day))
                ->values()
                ->all());

        // Days with a complete time log, from a week before the cut-off (for the prior-day rule)
        $presentDays = DB::table('time_logs')
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('work_date', [Carbon::parse($start)->subDays(7)->toDateString(), $end])
            ->whereNotNull('time_in')
            ->whereNotNull('time_out')
            ->get(['employee_id', 'work_date'])
            ->groupBy('employee_id')
            ->map(fn ($rows) => $rows->pluck('work_date')->map(fn ($d) => substr((string) $d, 0, 10))->flip()->all());

        $rules = HolidayPayRule::query()
            ->where('is_status', true)
            ->whereIn('holiday_type_id', $holidayRows->pluck('holiday_type_id')->unique())
            ->get();

        return [
            'holidays' => $holidays,
            'employments' => $employments,
            'scheduleDays' => $scheduleDays,
            'presentDays' => $presentDays,
            'rules' => $rules,
        ];
    }

    /**
     * How each holiday in the cut-off applies to one employee (used by Payroll Readiness and payroll).
     *
     * Each entry: date, holiday, type, covered, worked, rest_day, paid, reason.
     * "paid" = the day counts as paid (worked, or covered and unworked on a working day).
     */
    public function daysFor(array $context, int $employeeId, Collection $logs): array
    {
        if ($context['holidays']->isEmpty()) {
            return [];
        }

        $employment = $context['employments']->get($employeeId);
        $locationId = $employment?->location_id ? (int) $employment->location_id : null;
        $workingDays = $context['scheduleDays']->get($employment?->work_schedule_id, []);
        $present = $context['presentDays']->get($employeeId, []);
        $logsByDate = $logs->keyBy(fn ($log) => substr((string) $log->work_date, 0, 10));

        $days = [];

        foreach ($context['holidays'] as $date => $holidaysOnDate) {
            $covered = $holidaysOnDate->filter(fn ($h) => $this->covers($h, $employeeId, $locationId));
            $holiday = ($covered->isNotEmpty() ? $covered : $holidaysOnDate)
                ->sortByDesc(fn ($h) => self::TYPE_PRIORITY[strtolower((string) $h->type_code)] ?? 0)
                ->first();

            $log = $logsByDate->get($date);
            $worked = (bool) ($log && $log->time_in && $log->time_out);
            $restDay = $this->isRestDay($date, $workingDays);
            $isCovered = $covered->isNotEmpty();

            [$paid, $reason] = match (true) {
                $worked => [true, 'Worked'],
                !$isCovered => [false, 'Not covered by this holiday'],
                $restDay => [false, 'Falls on a rest day'],
                $holiday->requires_prior_day_present && !$this->presentBefore($date, $present, $workingDays)
                    => [false, 'Absent on the working day before'],
                default => [true, 'Paid holiday'],
            };

            $days[] = [
                'date' => $date,
                'holiday_id' => (int) $holiday->holiday_id,
                'holiday_name' => $holiday->holiday_name,
                'type' => $holiday->type_name,
                'covered' => $isCovered,
                'worked' => $worked,
                'rest_day' => $restDay,
                'paid' => $paid,
                'reason' => $reason,
            ];
        }

        return $days;
    }

    /**
     * Holiday pay lines for one employee (amounts added to gross pay).
     *
     * @param  Collection  $logs      the employee's time logs in the cut-off
     * @param  Collection  $overtime  the employee's approved OT requests in the cut-off
     */
    public function linesFor(
        array $context,
        int $employeeId,
        string $rateType,
        float $dailyRate,
        float $minuteRate,
        Collection $logs,
        Collection $overtime
    ): array {
        if ($context['holidays']->isEmpty()) {
            return [];
        }

        $employment = $context['employments']->get($employeeId);
        $locationId = $employment?->location_id ? (int) $employment->location_id : null;
        $workingDays = $context['scheduleDays']->get($employment?->work_schedule_id, []);
        $present = $context['presentDays']->get($employeeId, []);
        $salaryGroup = $rateType === 'monthly' ? 'monthly_paid' : 'daily_paid';

        $logsByDate = $logs->keyBy(fn ($log) => substr((string) $log->work_date, 0, 10));
        $otByDate = $overtime->groupBy(fn ($ot) => substr((string) $ot->ot_date, 0, 10));

        $lines = [];

        foreach ($context['holidays'] as $date => $holidaysOnDate) {
            $holiday = $holidaysOnDate
                ->filter(fn ($h) => $this->covers($h, $employeeId, $locationId))
                ->sortByDesc(fn ($h) => self::TYPE_PRIORITY[strtolower((string) $h->type_code)] ?? 0)
                ->first();

            if (!$holiday) {
                continue;
            }

            $isRestDay = $this->isRestDay($date, $workingDays);

            $log = $logsByDate->get($date);
            $worked = $log && $log->time_in && $log->time_out;

            if ($worked) {
                $scenario = $isRestDay ? 'rest_day_worked' : 'worked';
                $otScenario = $isRestDay ? 'rest_day_overtime' : 'worked_overtime';

                $rule = $this->rule($context['rules'], $holiday->holiday_type_id, $scenario, $salaryGroup, $date);

                if (!$rule) {
                    continue;
                }

                $workedMinutes = min((int) ($log->worked_minutes ?? self::MINUTES_PER_DAY), self::MINUTES_PER_DAY);

                // Normal day: basic pay already has 100%, so only the extra is added.
                // Rest day: basic pay has nothing for that day, so the full rate is added.
                $alreadyPaid = $isRestDay ? 0.0 : 1.0;
                $amount = $minuteRate * $workedMinutes * ((float) $rule->rate_percent / 100 - $alreadyPaid);

                $otMinutes = (int) $otByDate->get($date, collect())
                    ->sum(fn ($ot) => $ot->start_at && $ot->end_at
                        ? (int) abs($ot->start_at->diffInMinutes($ot->end_at))
                        : 0);

                if ($otMinutes > 0) {
                    $otRule = $this->rule($context['rules'], $holiday->holiday_type_id, $otScenario, $salaryGroup, $date);

                    if ($otRule) {
                        $amount += $minuteRate * $otMinutes * ((float) $otRule->rate_percent / 100 - self::REGULAR_OT_MULTIPLIER);
                    }
                }

                $this->push($lines, $holiday, $rule, (float) $rule->rate_percent, $date, $scenario, $log->id, $workedMinutes, $otMinutes, $dailyRate, $amount);
                continue;
            }

            // Unworked: only on a scheduled working day, and the prior-day rule when the type requires it
            if ($isRestDay) {
                continue;
            }

            if ($holiday->requires_prior_day_present && !$this->presentBefore($date, $present, $workingDays)) {
                continue;
            }

            // Monthly salary already includes the day
            if ($rateType === 'monthly') {
                continue;
            }

            $rule = $this->rule($context['rules'], $holiday->holiday_type_id, 'unworked', $salaryGroup, $date);
            $rate = $rule ? (float) $rule->rate_percent : self::DEFAULT_UNWORKED_RATE;

            $amount = $dailyRate * $rate / 100;

            $this->push($lines, $holiday, $rule, $rate, $date, 'unworked', null, 0, 0, $dailyRate, $amount);
        }

        return $lines;
    }

    /** Holiday Coverage: locations always apply first, then the selected employees. */
    private function covers(object $holiday, int $employeeId, ?int $locationId): bool
    {
        $inLocation = $holiday->location_ids === [] || in_array($locationId, $holiday->location_ids, true);
        $picked = in_array($employeeId, $holiday->selected_ids, true);

        return match ($holiday->policy) {
            'only' => $inLocation && $picked,
            'except' => $inLocation && !$picked,
            default => $inLocation,
        };
    }

    private function isRestDay(string $date, array $workingDays): bool
    {
        return $workingDays !== []
            && !in_array(strtolower(Carbon::parse($date)->format('l')), $workingDays, true);
    }

    /** The most specific active rule for the date: daily_paid / monthly_paid before "all", then the latest start. */
    private function rule(Collection $rules, int $typeId, string $scenario, string $salaryGroup, string $date): ?HolidayPayRule
    {
        return $rules
            ->filter(fn (HolidayPayRule $r) => (int) $r->holiday_type_id === $typeId
                && $r->scenario === $scenario
                && in_array($r->applies_to, [$salaryGroup, 'all'], true)
                && $r->effective_from->toDateString() <= $date
                && ($r->effective_to === null || $r->effective_to->toDateString() >= $date))
            ->sort(function (HolidayPayRule $a, HolidayPayRule $b) {
                $specificity = ($b->applies_to === 'all' ? 0 : 1) <=> ($a->applies_to === 'all' ? 0 : 1);

                return $specificity !== 0
                    ? $specificity
                    : $b->effective_from->timestamp <=> $a->effective_from->timestamp;
            })
            ->first();
    }

    /** Present on the last scheduled working day before the holiday (looks back up to 7 days). */
    private function presentBefore(string $date, array $present, array $workingDays): bool
    {
        $day = Carbon::parse($date)->subDay();

        for ($i = 0; $i < 7; $i++) {
            if (!$this->isRestDay($day->toDateString(), $workingDays)) {
                return isset($present[$day->toDateString()]);
            }

            $day->subDay();
        }

        return false;
    }

    private function push(
        array &$lines,
        object $holiday,
        ?HolidayPayRule $rule,
        float $ratePercent,
        string $date,
        string $scenario,
        ?int $timeLogId,
        int $workedMinutes,
        int $otMinutes,
        float $dailyRate,
        float $amount
    ): void {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            return;
        }

        $lines[] = [
            'holiday_id' => (int) $holiday->holiday_id,
            'holiday_name' => $holiday->holiday_name,
            'holiday_pay_rule_id' => $rule?->id,
            'time_log_id' => $timeLogId,
            'holiday_date' => $date,
            'scenario' => $scenario,
            'worked_minutes' => $workedMinutes,
            'overtime_minutes' => $otMinutes,
            'daily_rate' => round($dailyRate, 2),
            'rate_percent' => $ratePercent,
            'amount' => $amount,
        ];
    }
}