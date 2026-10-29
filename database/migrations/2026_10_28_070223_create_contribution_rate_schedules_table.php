<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contribution_rate_schedules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('contribution_type_id')
                ->constrained('contribution_types')
                ->restrictOnDelete();

            $table->enum('calculation_method', [
                'percentage',
                'bracket',
                'fixed',
            ]);

            $table->string('salary_basis');

            $table->decimal('employee_rate', 8, 5)->nullable();
            $table->decimal('employer_rate', 8, 5)->nullable();

            $table->decimal('minimum_salary_base', 12, 2)->nullable();
            $table->decimal('maximum_salary_base', 12, 2)->nullable();

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->boolean('is_status')->default(true);

            $table->timestamps();

            $table->index([
                'contribution_type_id',
                'effective_from',
                'effective_to',
            ], 'contribution_rate_effective_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contribution_rate_schedules');
    }
};
