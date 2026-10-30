<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_type_id')->constrained('schedule_types')->restrictOnDelete();
            $table->string('schedule_code', 50)->unique();
            $table->string('schedule_name');
            $table->decimal('required_work_hours', 4, 2);
            $table->unsignedSmallInteger('grace_period')->default(0);
            $table->time('break_start')->nullable();
            $table->time('break_end')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_schedules');
    }
};
