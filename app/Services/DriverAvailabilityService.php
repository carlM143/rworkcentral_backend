<?php

namespace App\Services;

use App\Models\DriverInfo;
use App\Models\TripTicket;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DriverAvailabilityService
{
    private const DAY_MAP = [
        'mon' => 'Monday',
        'tue' => 'Tuesday',
        'wed' => 'Wednesday',
        'thu' => 'Thursday',
        'fri' => 'Friday',
        'sat' => 'Saturday',
        'sun' => 'Sunday',
    ];

    public function __construct(private readonly TripScheduleService $schedule)
    {
    }

    /**
     * Employee ids with an approved leave on the date (one query for the whole list).
     *
     * @return Collection<int, int>
     */
    public function employeesOnLeave(Carbon $date): Collection
    {
        return DB::table('leave_request_dates as dates')
            ->join('leave_requests as requests', 'requests.id', '=', 'dates.leave_request_id')
            ->where('requests.status', 'approved')
            ->whereDate('dates.leave_date', $date->toDateString())
            ->pluck('requests.employee_id')
            ->unique()
            ->values();
    }

    /**
     * Non-cancelled trips on the date grouped by driver id (one query).
     *
     * @return Collection<int, Collection<int, TripTicket>>
     */
    public function tripsByDriver(Carbon $date): Collection
    {
        return TripTicket::query()
            ->whereDate('trip_date', $date->toDateString())
            ->where('status', '!=', 'cancelled')
            ->orderBy('scheduled_departure')
            ->get()
            ->groupBy('driver_id');
    }

    /**
     * Expects employment.workSchedule.days to be loaded.
     *
     * @param  Collection<int, int>  $onLeave
     * @param  Collection<int, TripTicket>  $trips  trips of this driver on the date
     */
    public function forDate(DriverInfo $driver, Carbon $date, Collection $onLeave, Collection $trips): array
    {
        $employment = $driver->employment;
        $busy = $trips->map(function (TripTicket $ticket): array {
            [$start, $end] = $this->schedule->window($ticket);

            return [
                'ticket_no' => $ticket->ticket_no,
                'destination' => $ticket->destination,
                'start' => $this->schedule->time($ticket->departed_at ?? $ticket->scheduled_departure),
                'end' => $this->schedule->time($ticket->returned_at ?? $ticket->expected_return),
                'status' => $ticket->status,
                '_window_end' => $end,
            ];
        })->values();

        $publicBusy = $busy->map(fn (array $b) => collect($b)->except('_window_end')->all())->all();

        if ($driver->expire_date && $driver->expire_date->lt($date->copy()->startOfDay())) {
            return $this->result('license_expired', 'License expired '.$driver->expire_date->toDateString(), $publicBusy);
        }

        if ($employment && $onLeave->contains($employment->employee_id)) {
            return $this->result('on_leave', 'Approved leave for the whole day', $publicBusy);
        }

        $workingDays = $this->workingDayNames($driver);
        if ($workingDays !== [] && ! in_array($date->format('l'), $workingDays, true)) {
            return $this->result('rest_day', 'Rest day in work schedule', $publicBusy);
        }

        if ($date->isToday()) {
            $now = now()->format('H:i');
            $current = $busy->firstWhere('status', 'ongoing')
                ?? $busy->first(fn ($b) => $b['status'] === 'scheduled' && $now >= $b['start'] && $now < $b['end']);

            if ($current) {
                $late = $current['status'] === 'ongoing' && $now >= $current['end'];

                return $this->result(
                    'on_trip',
                    $late
                        ? "{$current['ticket_no']} · running late (expected ".$this->timeText($current['end']).')'
                        : "{$current['ticket_no']} · back by ".$this->timeText($current['end']),
                    $publicBusy,
                    $late ? null : $current['end'],
                );
            }

            $next = $busy->first(fn ($b) => $b['status'] === 'scheduled' && $b['start'] > $now);

            return $this->result(
                'available',
                $next ? 'Next trip at '.$this->timeText($next['start']) : 'Free for the rest of the day',
                $publicBusy,
            );
        }

        $note = $busy->isEmpty()
            ? 'Free all day'
            : $busy->count().' trip'.($busy->count() > 1 ? 's' : '').' scheduled · '
                .$busy->map(fn ($b) => $this->timeText($b['start']).'–'.$this->timeText($b['end']))->implode(', ');

        return $this->result('available', $note, $publicBusy);
    }

    /**
     * @return array<int, string>
     */
    private function workingDayNames(DriverInfo $driver): array
    {
        $days = $driver->employment?->workSchedule?->days ?? collect();

        return $days
            ->where('is_working_day', true)
            ->map(fn ($day) => self::DAY_MAP[strtolower(substr(trim((string) $day->day_of_week), 0, 3))] ?? null)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function timeText(string $hhmm): string
    {
        return Carbon::createFromFormat('H:i', $hhmm)->format('g:i A');
    }

    private function result(string $status, string $note, array $busy = [], ?string $availableFrom = null): array
    {
        return [
            'status' => $status,
            'note' => $note,
            'available_from' => $availableFrom,
            'busy' => $busy,
        ];
    }
}