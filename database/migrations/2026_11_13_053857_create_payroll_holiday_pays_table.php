<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_holiday_pays', function (Blueprint $table) {
            $table->id();

            // Rebuilt every time the payroll is regenerated
            $table->foreignId('payroll_id')->constrained('payrolls')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('holiday_id')->constrained('holidays')->restrictOnDelete();
            $table->foreignId('holiday_pay_rule_id')->constrained('holiday_pay_rules')->restrictOnDelete();
            $table->foreignId('time_log_id')->nullable()->constrained('time_logs')->nullOnDelete();

            $table->date('holiday_date');

            $table->enum('scenario', [
                'unworked',
                'worked',
                'worked_overtime',
                'rest_day_worked',
                'rest_day_overtime',
            ]);

            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);

            // Snapshots at generation time, so later rate changes don't alter past payrolls
            $table->decimal('daily_rate', 12, 2);
            $table->decimal('rate_percent', 6, 2);

            // Premium added to pay (on top of basic pay already covering the day)
            $table->decimal('amount', 12, 2)->default(0);

            $table->timestamps();

            $table->unique(['payroll_id', 'holiday_id', 'holiday_date'], 'payroll_holiday_pays_unique');
            $table->index(['employee_id', 'holiday_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_holiday_pays');
    }
};