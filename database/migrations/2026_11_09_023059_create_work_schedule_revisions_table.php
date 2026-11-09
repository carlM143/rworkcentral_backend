<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_schedule_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_schedule_id')->constrained('work_schedules')->cascadeOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->decimal('required_work_hours', 4, 2);
            $table->unsignedSmallInteger('grace_period')->default(0);
            $table->time('break_start')->nullable();
            $table->time('break_end')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable(); // when copied into the current snapshot
            $table->timestamps();

            $table->unique(['work_schedule_id', 'effective_from'], 'wsr_schedule_effective_unique');
        });

        Schema::create('work_schedule_revision_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_schedule_revision_id')->constrained('work_schedule_revisions')->cascadeOnDelete();
            $table->string('day_of_week', 10); // Monday ... Sunday
            $table->boolean('is_working_day')->default(true);
            $table->time('time_in')->nullable();
            $table->time('time_out')->nullable();
            $table->timestamps();

            $table->unique(['work_schedule_revision_id', 'day_of_week'], 'wsrd_revision_day_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_schedule_revision_days');
        Schema::dropIfExists('work_schedule_revisions');
    }
};