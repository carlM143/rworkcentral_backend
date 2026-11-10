<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_periods', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->restrictOnDelete();

            $table->unsignedTinyInteger('cutoff_number');

            $table->string('period_name');

            // Salary period being paid, e.g. Oct 16–31
            $table->date('pay_period_start');
            $table->date('pay_period_end');

            // Attendance days counted, e.g. Oct 8–22
            $table->date('cutoff_start');
            $table->date('cutoff_end');

            $table->date('payment_date');

            $table->enum('status', [
                'draft',
                'open',
                'processing',
                'closed',
                'cancelled',
            ])->default('draft');

            $table->timestamps();

            $table->index([
                'company_id',
                'cutoff_number',
            ], 'payroll_period_company_cutoff_idx');

            $table->unique([
                'company_id',
                'pay_period_start',
            ], 'payroll_period_company_pay_start_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_periods');
    }
};