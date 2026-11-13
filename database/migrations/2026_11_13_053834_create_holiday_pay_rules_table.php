<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holiday_pay_rules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('holiday_type_id')
                ->constrained('holiday_types')
                ->restrictOnDelete();

            $table->enum('scenario', [
                'unworked',
                'worked',
                'worked_overtime',
                'rest_day_worked',
                'rest_day_overtime',
            ]);

            // % of the daily rate for that day, e.g. 200.00 for a worked regular holiday
            $table->decimal('rate_percent', 6, 2);

            $table->enum('applies_to', ['all', 'daily_paid', 'monthly_paid'])->default('all');

            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_status')->default(true);

            $table->timestamps();

            $table->unique(
                ['holiday_type_id', 'scenario', 'applies_to', 'effective_from'],
                'holiday_pay_rules_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_pay_rules');
    }
};