<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oba_requests', function (Blueprint $table) {
            $table->id();

            // The employee who FILED the form (not an owner or requester).
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            // Step 1: Department Head (users.id from departments.admin_id).
            $table->foreignId('dept_head_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dept_head_acted_at')->nullable();

            // Step 2: HR.
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('reason');
            $table->string('destination');
            $table->dateTime('time_from');
            $table->dateTime('time_to');
            $table->dateTime('date_request');

            // pending, dept_head_approved, approved, rejected, cancelled
            $table->string('status', 30)->default('pending');
            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'time_from', 'time_to']);
            $table->index('dept_head_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oba_requests');
    }
};