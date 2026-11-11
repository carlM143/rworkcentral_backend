<?php

namespace Database\Seeders;

use App\Models\WorkSchedule;
use App\Models\WorkScheduleDay;
use App\Models\WorkScheduleRevision;
use App\Models\WorkScheduleRevisionDay;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WorkScheduleDaySeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->schedules() as $code => $days) {
            $schedule = WorkSchedule::where('schedule_code', $code)->firstOrFail();

            DB::transaction(function () use ($schedule, $days) {
                $this->seedCurrentDays($schedule, $days);
                $this->seedInitialRevision($schedule, $days);
            });
        }
    }

    private function schedules(): array
    {
        return [
            // R Web Solutions
            'WS-001' => [
                $this->day('Monday', '09:00:00', '18:00:00'),
                $this->day('Tuesday', '08:00:00', '18:00:00'),
                $this->day('Wednesday', '08:00:00', '18:00:00'),
                $this->day('Thursday', '08:00:00', '18:00:00'),
                $this->day('Friday', '08:00:00', '18:00:00'),
                $this->day('Saturday', '08:00:00', '12:00:00'),
                $this->restDay('Sunday'),
            ],

            // Airpro
            'WS-002' => [
                $this->day('Monday', '08:00:00', '17:00:00'),
                $this->day('Tuesday', '08:00:00', '17:00:00'),
                $this->day('Wednesday', '08:00:00', '17:00:00'),
                $this->day('Thursday', '08:00:00', '17:00:00'),
                $this->day('Friday', '08:00:00', '17:00:00'),
                $this->day('Saturday', '08:00:00', '17:00:00'),
                $this->restDay('Sunday'),
            ],
        ];
    }

    private function day(string $name, string $timeIn, string $timeOut): array
    {
        return [
            'day_of_week' => $name,
            'is_working_day' => true,
            'time_in' => $timeIn,
            'time_out' => $timeOut,
        ];
    }

    private function restDay(string $name): array
    {
        return [
            'day_of_week' => $name,
            'is_working_day' => false,
            'time_in' => null,
            'time_out' => null,
        ];
    }

    private function seedCurrentDays(WorkSchedule $schedule, array $days): void
    {
        WorkScheduleDay::where('work_schedule_id', $schedule->id)
            ->whereNotIn('day_of_week', array_column($days, 'day_of_week'))
            ->delete();

        foreach ($days as $day) {
            WorkScheduleDay::updateOrCreate(
                [
                    'work_schedule_id' => $schedule->id,
                    'day_of_week' => $day['day_of_week'],
                ],
                [
                    'is_working_day' => $day['is_working_day'],
                    'time_in' => $day['time_in'],
                    'time_out' => $day['time_out'],
                ]
            );
        }
    }

    private function seedInitialRevision(WorkSchedule $schedule, array $days): void
    {
        $revision = WorkScheduleRevision::where('work_schedule_id', $schedule->id)
            ->orderBy('effective_from')
            ->first();

        if (!$revision) {
            $revision = WorkScheduleRevision::create([
                'work_schedule_id' => $schedule->id,
                'effective_from' => ($schedule->created_at ?? now())->toDateString(),
                'effective_to' => null,
                'required_work_hours' => $schedule->required_work_hours ?? 8,
                'grace_period' => $schedule->grace_period ?? 0,
                'break_start' => $schedule->break_start,
                'break_end' => $schedule->break_end,
                'reason' => 'Initial schedule',
                'applied_at' => now(),
            ]);
        }

        WorkScheduleRevisionDay::where('work_schedule_revision_id', $revision->id)
            ->whereNotIn('day_of_week', array_column($days, 'day_of_week'))
            ->delete();

        foreach ($days as $day) {
            WorkScheduleRevisionDay::updateOrCreate(
                [
                    'work_schedule_revision_id' => $revision->id,
                    'day_of_week' => $day['day_of_week'],
                ],
                [
                    'is_working_day' => $day['is_working_day'],
                    'time_in' => $day['time_in'],
                    'time_out' => $day['time_out'],
                ]
            );
        }
    }
}