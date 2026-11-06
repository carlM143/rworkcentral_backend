
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_payroll_group_assignments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained('employees')
                ->restrictOnDelete();

            $table->foreignId('payroll_group_id')
                ->constrained('payroll_groups')
                ->restrictOnDelete();

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->foreignId('assigned_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['employee_id', 'effective_from', 'effective_to'], 'employee_group_dates_idx');
            $table->index(['payroll_group_id', 'effective_from'], 'payroll_group_start_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_payroll_group_assignments');
    }
};
