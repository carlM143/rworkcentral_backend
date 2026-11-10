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
    private const SCHEDULE_CODES = ['WS-001', 'WS-002'];

    public function run(): void
    {
        $days = [
            ['day_of_week' => 'Monday', 'is_working_day' => true, 'time_in' => '08:00:00', 'time_out' => '16:00:00'],
            ['day_of_week' => 'Tuesday', 'is_working_day' => true, 'time_in' => '08:00:00', 'time_out' => '16:00:00'],
            ['day_of_week' => 'Wednesday', 'is_working_day' => true, 'time_in' => '09:00:00', 'time_out' => '17:00:00'],
            ['day_of_week' => 'Thursday', 'is_working_day' => true, 'time_in' => '08:00:00', 'time_out' => '16:00:00'],
            ['day_of_week' => 'Friday', 'is_working_day' => true, 'time_in' => '08:00:00', 'time_out' => '16:00:00'],
            ['day_of_week' => 'Saturday', 'is_working_day' => true, 'time_in' => '08:00:00', 'time_out' => '12:00:00'],
            ['day_of_week' => 'Sunday', 'is_working_day' => false, 'time_in' => null, 'time_out' => null],
        ];

        foreach (self::SCHEDULE_CODES as $code) {
            $schedule = WorkSchedule::where('schedule_code', $code)->firstOrFail();

            DB::transaction(function () use ($schedule, $days) {
                $this->seedCurrentDays($schedule, $days);
                $this->seedInitialRevision($schedule, $days);
            });
        }
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