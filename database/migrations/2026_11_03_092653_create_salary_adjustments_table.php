
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_adjustments', function (Blueprint $table) {
            $table->id();

            // Employee
            $table->foreignId('employee_id')
                ->constrained('employees')
                ->restrictOnDelete();

            // Existing Salary Setup
            $table->foreignId('salary_setup_id')
                ->constrained('salary_setups')
                ->restrictOnDelete();

            // Adjustment Information
            $table->string('adjustment_type');

            // Basic Salary
            $table->decimal('previous_basic_salary', 12, 2);
            $table->decimal('new_basic_salary', 12, 2);

            // Allowance
            $table->decimal('previous_allowance', 12, 2)->nullable();
            $table->decimal('new_allowance', 12, 2)->nullable();

            // Effective Date
            $table->date('effective_date');

            // Reason
            $table->text('reason')->nullable();

            // Approval Information
            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->date('approved_at')->nullable();

            // Application Information
            $table->foreignId('applied_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('applied_at')->nullable();

            // Adjustment Status
            $table->string('status')->default('pending');

            $table->timestamps();

            // Indexes
            $table->index(['employee_id', 'status']);
            $table->index('effective_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_adjustments');
    }
};
