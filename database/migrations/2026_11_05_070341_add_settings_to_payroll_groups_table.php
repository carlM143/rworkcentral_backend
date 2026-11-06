<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_groups', function (Blueprint $table) {
            $table->text('description')->nullable();
            $table->string('frequency', 30)->default('semi_monthly');
            $table->boolean('is_active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('payroll_groups', function (Blueprint $table) {
            $table->dropColumn([
                'description',
                'frequency',
                'is_active',
            ]);
        });
    }
};
