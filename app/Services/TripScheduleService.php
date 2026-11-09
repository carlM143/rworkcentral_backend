<?php

namespace App\Services;

use App\Models\TripTicket;
use Illuminate\Support\Collection;

/**
 * Time-window helpers shared by trip tickets and driver availability.
 * Times are stored as TIME (HH:MM:SS) and compared as "HH:MM" strings.
 */
class TripScheduleService
{
    public function time(?string $value): ?string
    {
        return $value ? substr($value, 0, 5) : null;
    }

    /**
     * Window a ticket occupies: actual times when known, otherwise the plan.
     * A trip still out after its expected return keeps the driver busy all day.
     *
     * @return array{0: string, 1: string}
     */
    public function window(TripTicket $ticket): array
    {
        $start = $this->time($ticket->departed_at ?? $ticket->scheduled_departure);
        $end = $this->time($ticket->returned_at ?? $ticket->expected_return);

        if ($ticket->status === 'ongoing' && $ticket->trip_date?->isToday() && now()->format('H:i') >= $end) {
            $end = '23:59';
        }

        return [$start, $end];
    }

    /**
     * Non-cancelled tickets on the date that overlap [start, end).
     *
     * @return Collection<int, TripTicket>
     */
    public function overlapping(string $date, string $start, string $end, ?int $excludeId = null): Collection
    {
        return TripTicket::query()
            ->whereDate('trip_date', $date)
            ->where('status', '!=', 'cancelled')
            ->when($excludeId, fn ($q) => $q->whereKeyNot($excludeId))
            ->with('passengers:employees.id')
            ->get()
            ->filter(function (TripTicket $ticket) use ($start, $end): bool {
                [$s, $e] = $this->window($ticket);

                return $start < $e && $s < $end;
            })
            ->values();
    }

    /**
     * Highest odometer reading recorded for the vehicle.
     */
    public function lastOdometer(int $vehicleId, ?int $excludeId = null): ?int
    {
        $query = TripTicket::query()
            ->where('vehicle_id', $vehicleId)
            ->when($excludeId, fn ($q) => $q->whereKeyNot($excludeId));

        $max = max((int) (clone $query)->max('odometer_out'), (int) (clone $query)->max('odometer_in'));

        return $max > 0 ? $max : null;
    }

    /**
     * Highest odometer per vehicle, for list responses (one query).
     *
     * @return Collection<int, int>
     */
    public function lastOdometerByVehicle(): Collection
    {
        return TripTicket::query()
            ->selectRaw('vehicle_id, GREATEST(COALESCE(MAX(odometer_out), 0), COALESCE(MAX(odometer_in), 0)) AS reading')
            ->groupBy('vehicle_id')
            ->pluck('reading', 'vehicle_id')
            ->map(fn ($reading) => (int) $reading);
    }

    public function minutesBetween(?string $from, ?string $to): ?int
    {
        if (! $from || ! $to) {
            return null;
        }

        [$fh, $fm] = array_map('intval', explode(':', $this->time($from)));
        [$th, $tm] = array_map('intval', explode(':', $this->time($to)));

        return ($th * 60 + $tm) - ($fh * 60 + $fm);
    }
}