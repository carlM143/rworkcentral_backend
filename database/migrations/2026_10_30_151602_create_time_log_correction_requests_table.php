
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_log_correction_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('time_log_id')
                ->constrained('time_logs')
                ->restrictOnDelete();

            $table->foreignId('employee_id')
                ->constrained('employees')
                ->restrictOnDelete();

            $table->foreignId('requested_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Correction selection: Time In or Time Out
            $table->enum('correction_type', [
                'time_in',
                'time_out',
            ]);

            $table->string('status', 20)->default('pending');

            // Original attendance snapshot
            $table->dateTime('original_time_in')->nullable();
            $table->dateTime('original_time_out')->nullable();

            // Requested time only (HH:MM:SS)
            $table->time('requested_time');

            $table->text('reason');
            $table->string('attachment_path')->nullable();
            $table->text('review_remarks')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->foreignId('applied_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('applied_at')
                ->nullable();

            $table->index(['time_log_id', 'status']);
            $table->index(['employee_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_log_correction_requests');
    }
};
