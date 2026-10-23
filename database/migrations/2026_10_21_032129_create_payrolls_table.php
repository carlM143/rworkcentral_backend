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
            $table->unique(['employee_id', 'payroll_period_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payrolls');
    }
};