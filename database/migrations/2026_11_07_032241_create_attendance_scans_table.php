<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_scans', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained('employees')
                ->restrictOnDelete();

            $table->foreignId('attendance_qr_session_id')
                ->nullable()
                ->constrained('attendance_qr_sessions')
                ->nullOnDelete();

            $table->foreignId('attendance_qr_token_id')
                ->nullable()
                ->constrained('attendance_qr_tokens')
                ->nullOnDelete();

            $table->foreignId('time_log_id')
                ->nullable()
                ->constrained('time_logs')
                ->nullOnDelete();

            // time_in or time_out
            $table->string('action');

            // accepted or rejected
            $table->string('result');

            $table->dateTime('scanned_at');

            $table->string('rejection_reason')->nullable();
            $table->string('device_info')->nullable();
            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'scanned_at']);
            $table->index(['attendance_qr_session_id', 'result']);
            $table->index(['time_log_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_scans');
    }
};