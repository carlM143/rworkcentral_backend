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
            $table->date('cutoff_start');
            $table->date('cutoff_end');
            $table->date('payment_date');

            $table->enum('status', [
                'draft',
                'open',
                'processing',
                'completed',
                'cancelled',
            ])->default('draft');

            $table->timestamps();

            $table->index([
                'company_id',
                'cutoff_number',
            ], 'payroll_period_company_cutoff_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_periods');
    }
};
