<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('payroll_group_id')->constrained('payroll_groups')->restrictOnDelete();
            $table->foreignId('payroll_period_id')->constrained('payroll_periods')->restrictOnDelete();
            $table->foreignId('salary_type_id')->nullable()->constrained('salary_types')->restrictOnDelete();

            $table->decimal('basic_salary', 12, 2);
            $table->decimal('allowance', 12, 2)->nullable();
            $table->string('bank_name');
            $table->string('account_name');
            $table->string('account_number');

            $table->string('status', 20)->default('draft');

            $table->decimal('daily_rate', 12, 2)->default(0);
            $table->unsignedInteger('days_worked')->default(0);
            $table->unsignedInteger('worked_minutes')->default(0);

            $table->decimal('basic_pay', 12, 2)->default(0);
            $table->decimal('allowance_pay', 12, 2)->default(0);

            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->decimal('overtime_pay', 12, 2)->default(0);

            $table->unsignedInteger('late_minutes')->default(0);
            $table->decimal('late_deduction', 12, 2)->default(0);

            $table->unsignedInteger('undertime_minutes')->default(0);
            $table->decimal('undertime_deduction', 12, 2)->default(0);

            $table->decimal('unpaid_leave_days', 6, 2)->default(0);
            $table->decimal('unpaid_leave_deduction', 12, 2)->default(0);

            $table->decimal('holiday_pay', 12, 2)->default(0);

            $table->decimal('gross_pay', 12, 2)->default(0);
            $table->decimal('contributions_total', 12, 2)->default(0);
            $table->decimal('loan_deductions_total', 12, 2)->default(0);
            $table->decimal('withholding_tax', 12, 2)->default(0);
            $table->decimal('total_deductions', 12, 2)->default(0);
            $table->decimal('net_pay', 12, 2)->default(0);

            $table->json('breakdown')->nullable();

            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();

            $table->unique(['employee_id', 'payroll_period_id']);
            $table->index(['payroll_period_id', 'status']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payrolls');
    }
};