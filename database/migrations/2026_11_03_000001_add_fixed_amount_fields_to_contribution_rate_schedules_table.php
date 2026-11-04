<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the columns required by the "fixed" calculation method
     * (flat employee/employer amounts + deduction frequency) and allows
     * salary_basis to be empty because fixed contributions do not depend on salary.
     */
    public function up(): void
    {
        Schema::table('contribution_rate_schedules', function (Blueprint $table) {
            $table->decimal('employee_amount', 12, 2)->nullable()->after('employer_rate');
            $table->decimal('employer_amount', 12, 2)->nullable()->after('employee_amount');
            $table->string('frequency', 30)->nullable()->after('employer_amount');

            $table->string('salary_basis')->nullable()->change();
        });

        DB::table('contribution_rate_schedules')
            ->where('calculation_method', 'fixed')
            ->whereNull('employee_amount')
            ->update([
                'employee_amount' => DB::raw('employee_rate'),
                'employer_amount' => DB::raw('employer_rate'),
                'frequency' => 'monthly',
                'salary_basis' => null,
                'employee_rate' => null,
                'employer_rate' => null,
            ]);
    }

    public function down(): void
    {
        Schema::table('contribution_rate_schedules', function (Blueprint $table) {
            $table->dropColumn(['employee_amount', 'employer_amount', 'frequency']);
        });
    }
};

