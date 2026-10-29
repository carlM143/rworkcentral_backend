<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_contributions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payroll_id')
                ->constrained('payrolls')
                ->restrictOnDelete();

            $table->foreignId('employee_id')
                ->constrained('employees')
                ->restrictOnDelete();

            $table->foreignId('contribution_type_id')
                ->constrained('contribution_types')
                ->restrictOnDelete();

            $table->foreignId('contribution_rate_schedule_id')
                ->constrained('contribution_rate_schedules')
                ->restrictOnDelete();

            $table->date('contribution_month');

            $table->decimal('salary_basis_amount', 12, 2);
            $table->decimal('employee_share', 12, 2);
            $table->decimal('employer_share', 12, 2);
            $table->decimal('employer_ec_amount', 12, 2)->default(0);

            $table->string('reference_key')->unique();

            $table->timestamps();

            $table->unique([
                'payroll_id',
                'contribution_type_id',
                'contribution_month',
            ], 'payroll_contribution_unique');

            $table->index([
                'employee_id',
                'contribution_month',
            ], 'payroll_contribution_employee_month_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_contributions');
    }
};
