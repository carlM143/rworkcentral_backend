<?php

namespace App\Services;

use App\Models\WorkSchedule;
use App\Models\WorkScheduleRevision;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class WorkScheduleRevisionService
{
    public function create(WorkSchedule $schedule, array $config, array $days, string $effectiveFrom, ?string $reason = null): WorkScheduleRevision
    {
        $revision = $schedule->revisions()->create($config + [
            'effective_from' => Carbon::parse($effectiveFrom)->toDateString(),
            'reason'         => $reason,
            'created_by'     => auth()->id(),
        ]);

        $revision->days()->createMany($days);

        return $revision->load('days');
    }

    public function change(WorkSchedule $schedule, array $config, array $days, string $effectiveFrom, ?string $reason = null): WorkScheduleRevision
    {
        $date = Carbon::parse($effectiveFrom)->startOfDay();

        $latest = $schedule->revisions()
            ->orderByDesc('effective_from')
            ->lockForUpdate()
            ->first();

        if ($latest) {
            if ($latest->effective_from->gt($date)) {
                throw ValidationException::withMessages([
                    'effective_from' => "Effective date must be after {$latest->effective_from->toDateString()}.",
                ]);
            }

            if ($latest->effective_from->eq($date)) {
                // Same date: only allowed to correct a change that hasn't started yet
                if (! $latest->effective_from->isFuture()) {
                    throw ValidationException::withMessages([
                        'effective_from' => 'A change already took effect on this date. Choose a later date.',
                    ]);
                }

                $latest->update($config + ['reason' => $reason, 'created_by' => auth()->id()]);
                $latest->days()->delete();
                $latest->days()->createMany($days);

                return $latest->load('days');
            }

            $latest->update(['effective_to' => $date->copy()->subDay()->toDateString()]);
        }

        return $this->create($schedule, $config, $days, $date->toDateString(), $reason);
    }

    // Copies a revision into work_schedules + work_schedule_days (the UI snapshot)
    public function applyToSchedule(WorkSchedule $schedule, WorkScheduleRevision $revision): void
    {
        $revision->loadMissing('days');

        $schedule->update([
            'required_work_hours' => $revision->required_work_hours,
            'grace_period'        => $revision->grace_period,
            'break_start'         => $revision->break_start,
            'break_end'           => $revision->break_end,
        ]);

        $schedule->days()->delete();
        $schedule->days()->createMany(
            $revision->days->map(fn ($d) => [
                'day_of_week'    => $d->day_of_week,
                'is_working_day' => true,
                'time_in'        => $d->time_in,
                'time_out'       => $d->time_out,
            ])->all()
        );

        $revision->update(['applied_at' => now()]);
    }

    public function hasChanged(?WorkScheduleRevision $latest, array $config, array $days): bool
    {
        if (! $latest) {
            return true;
        }

        return (float) $latest->required_work_hours !== (float) $config['required_work_hours']
            || (int) $latest->grace_period !== (int) $config['grace_period']
            || $this->normalizeDays($latest->days->toArray()) !== $this->normalizeDays($days);
    }

    private function normalizeDays(array $days): array
    {
        return collect($days)
            ->mapWithKeys(fn ($d) => [
                $d['day_of_week'] => substr($d['time_in'], 0, 5) . '-' . substr($d['time_out'], 0, 5),
            ])
            ->sortKeys()
            ->all();
    }
}