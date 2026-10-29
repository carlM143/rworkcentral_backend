<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contribution_rate_brackets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('contribution_rate_schedule_id')
                ->constrained('contribution_rate_schedules')
                ->restrictOnDelete();

            $table->decimal('salary_from', 12, 2);
            $table->decimal('salary_to', 12, 2)->nullable();

            $table->decimal('monthly_salary_credit', 12, 2)->nullable();

            $table->decimal('employee_rate', 8, 5)->nullable();
            $table->decimal('employer_rate', 8, 5)->nullable();

            $table->decimal('employee_amount', 12, 2)->nullable();
            $table->decimal('employer_amount', 12, 2)->nullable();
            $table->decimal('employer_ec_amount', 12, 2)->nullable();

            $table->timestamps();

            $table->index([
                'contribution_rate_schedule_id',
                'salary_from',
            ], 'contribution_bracket_salary_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contribution_rate_brackets');
    }
};
