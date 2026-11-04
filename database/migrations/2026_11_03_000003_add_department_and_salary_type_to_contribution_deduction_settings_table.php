<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contribution_deduction_settings', function (Blueprint $table) {
            $table->foreignId('department_id')
                ->nullable()
                ->after('company_id')
                ->constrained('departments')
                ->nullOnDelete();

            $table->foreignId('salary_type_id')
                ->nullable()
                ->after('department_id')
                ->constrained('salary_types')
                ->nullOnDelete();

            $table->string('deduction_schedule', 50)
                ->nullable()
                ->after('contribution_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('contribution_deduction_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
            $table->dropConstrainedForeignId('salary_type_id');
            $table->dropColumn('deduction_schedule');
        });
    }
};

