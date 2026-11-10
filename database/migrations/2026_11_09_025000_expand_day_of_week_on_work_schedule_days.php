<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Allow full day names on work_schedule_days.day_of_week so the
 * backfill migration can convert Sat/Sun to Saturday/Sunday.
 * Must run before 2026_11_09_025500_backfill_work_schedule_revisions.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE work_schedule_days
            MODIFY day_of_week ENUM(
                'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday',
                'Saturday', 'Sunday', 'Sat', 'Sun'
            ) NOT NULL
        ");
    }

    public function down(): void
    {
        DB::table('work_schedule_days')->where('day_of_week', 'Saturday')->update(['day_of_week' => 'Sat']);
        DB::table('work_schedule_days')->where('day_of_week', 'Sunday')->update(['day_of_week' => 'Sun']);

        DB::statement("
            ALTER TABLE work_schedule_days
            MODIFY day_of_week ENUM(
                'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Sat', 'Sun'
            ) NOT NULL
        ");
    }
};