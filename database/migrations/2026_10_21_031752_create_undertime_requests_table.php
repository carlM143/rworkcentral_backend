<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('undertime_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('work_schedule_id')->constrained('work_schedules')->restrictOnDelete();
            $table->foreignId('time_log_id')->nullable()->constrained('time_logs')->nullOnDelete();
            $table->string('request_no')->unique();
            $table->date('undertime_date');
            $table->dateTime('requested_time_out');
            $table->dateTime('scheduled_time_out');
            $table->unsignedInteger('requested_minutes');
            $table->text('reason');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('file_path')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('undertime_requests');
    }
};