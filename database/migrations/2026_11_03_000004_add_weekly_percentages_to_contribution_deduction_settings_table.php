<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contribution_deduction_settings', function (Blueprint $table) {
            $table->decimal('week_1_percent', 5, 2)->nullable()->after('second_cutoff_percent');
            $table->decimal('week_2_percent', 5, 2)->nullable()->after('week_1_percent');
            $table->decimal('week_3_percent', 5, 2)->nullable()->after('week_2_percent');
            $table->decimal('week_4_percent', 5, 2)->nullable()->after('week_3_percent');
        });
    }

    public function down(): void
    {
        Schema::table('contribution_deduction_settings', function (Blueprint $table) {
            $table->dropColumn([
                'week_1_percent',
                'week_2_percent',
                'week_3_percent',
                'week_4_percent',
            ]);
        });
    }
};

