<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_schedule_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_schedule_id')->constrained('work_schedules')->cascadeOnDelete();
            $table->enum('day_of_week', ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Sat', 'Sun']);
            $table->boolean('is_working_day')->default(true);
            $table->time('time_in')->nullable();
            $table->time('time_out')->nullable();
            $table->timestamps();

            $table->unique(['work_schedule_id', 'day_of_week'], 'work_schedule_day_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_schedule_days');
    }
};
