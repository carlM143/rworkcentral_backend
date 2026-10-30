
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

            $table->string('correction_type', 50);
            $table->string('priority', 20)->default('normal');
            $table->string('status', 20)->default('pending');

            $table->dateTime('original_time_in')->nullable();
            $table->dateTime('original_time_out')->nullable();
            $table->dateTime('original_break_out')->nullable();
            $table->dateTime('original_break_in')->nullable();

            $table->dateTime('requested_time_in')->nullable();
            $table->dateTime('requested_time_out')->nullable();
            $table->dateTime('requested_break_out')->nullable();
            $table->dateTime('requested_break_in')->nullable();

            $table->text('reason');
            $table->string('attachment_path')->nullable();
            $table->text('review_remarks')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->index(['time_log_id', 'status']);
            $table->index(['employee_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_log_correction_requests');
    }
};
