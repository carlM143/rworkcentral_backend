<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('work_schedule_days')->where('day_of_week', 'Sat')->update(['day_of_week' => 'Saturday']);
        DB::table('work_schedule_days')->where('day_of_week', 'Sun')->update(['day_of_week' => 'Sunday']);

        $now = now();

        DB::table('work_schedules')->orderBy('id')->each(function ($schedule) use ($now) {
            $revisionId = DB::table('work_schedule_revisions')->insertGetId([
                'work_schedule_id'    => $schedule->id,
                'effective_from'      => Carbon::parse($schedule->created_at ?? $now)->toDateString(),
                'required_work_hours' => $schedule->required_work_hours,
                'grace_period'        => $schedule->grace_period,
                'break_start'         => $schedule->break_start,
                'break_end'           => $schedule->break_end,
                'reason'              => 'Initial schedule',
                'applied_at'          => $now,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);

            $days = DB::table('work_schedule_days')
                ->where('work_schedule_id', $schedule->id)
                ->where('is_working_day', true)
                ->get()
                ->map(fn ($d) => [
                    'work_schedule_revision_id' => $revisionId,
                    'day_of_week'    => $d->day_of_week,
                    'is_working_day' => true,
                    'time_in'        => $d->time_in,
                    'time_out'       => $d->time_out,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ])
                ->all();

            DB::table('work_schedule_revision_days')->insert($days);
        });
    }

    public function down(): void
    {
        DB::table('work_schedule_revisions')->delete();
    }
};