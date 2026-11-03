
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('work_schedule_id')
                ->nullable();

            $table->dateTime('scheduled_time_in')
                ->nullable();

            $table->dateTime('scheduled_time_out')
                ->nullable();

            $table->unsignedInteger('raw_late_minutes')
                ->nullable();

            $table->unsignedInteger('grace_period_minutes')
                ->nullable();

            $table->unsignedInteger('chargeable_late_minutes')
                ->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('time_logs', function (Blueprint $table) {
            $table->dropColumn([
                'work_schedule_id',
                'scheduled_time_in',
                'scheduled_time_out',
                'raw_late_minutes',
                'grace_period_minutes',
                'chargeable_late_minutes',
            ]);
        });
    }
};
