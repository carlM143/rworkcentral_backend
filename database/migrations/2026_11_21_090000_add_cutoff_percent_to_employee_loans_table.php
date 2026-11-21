<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_loans', function (Blueprint $table) {
            // Which cutoff of the month the loan is deducted in (like contribution deduction settings).
            // deduction_amount is the monthly amount; each cutoff deducts its percent of it.
            // Default: whole amount on the 1st cutoff (once a month).
            $table->decimal('first_cutoff_percent', 5, 2)->default(100)->after('deduction_amount');
            $table->decimal('second_cutoff_percent', 5, 2)->default(0)->after('first_cutoff_percent');
        });
    }

    public function down(): void
    {
        Schema::table('employee_loans', function (Blueprint $table) {
            $table->dropColumn(['first_cutoff_percent', 'second_cutoff_percent']);
        });
    }
};
