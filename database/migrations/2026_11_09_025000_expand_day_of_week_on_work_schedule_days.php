<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
{
    if (DB::getDriverName() === 'mysql') {
        DB::statement("
            ALTER TABLE work_schedule_days
            MODIFY day_of_week ENUM(
                'Monday','Tuesday','Wednesday','Thursday','Friday',
                'Saturday','Sunday','Sat','Sun'
            ) NOT NULL
        ");
    }
}

public function down(): void
{
    if (DB::getDriverName() === 'mysql') {
        DB::statement("
            ALTER TABLE work_schedule_days
            MODIFY day_of_week ENUM(
                'Monday','Tuesday','Wednesday','Thursday','Friday',
                'Saturday','Sunday'
            ) NOT NULL
        ");
    }
}
};