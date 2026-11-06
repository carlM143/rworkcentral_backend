<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rest_day_switch_requests', function (Blueprint $table) {
            $table->id();

            $table->string('request_number', 30)->unique();

            $table->foreignId('employee_id')
                ->constrained('employees')
                ->restrictOnDelete();

            $table->foreignId('employment_id')
                ->nullable()
                ->constrained('employments')
                ->nullOnDelete();

            $table->foreignId('work_schedule_id')
                ->nullable()
                ->constrained('work_schedules')
                ->nullOnDelete();

            $table->string('current_schedule')->nullable();
            $table->string('current_rest_day', 20);
            $table->string('requested_rest_day', 20);

            $table->date('original_rest_date');
            $table->date('effective_date');
            $table->date('week_start');
            $table->date('week_end');

            $table->text('reason');

            $table->enum('status', [
                'pending',
                'approved',
                'rejected',
                'cancelled',
            ])->default('pending');

            $table->foreignId('requested_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_remarks')->nullable();

            $table->foreignId('cancelled_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'week_start', 'status']);
            $table->index(['status', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rest_day_switch_requests');
    }
};
