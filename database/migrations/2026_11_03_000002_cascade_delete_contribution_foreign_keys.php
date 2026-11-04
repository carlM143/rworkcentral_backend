<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('contribution_rate_schedules', function (Blueprint $table) {
            $table->dropForeign(['contribution_type_id']);
            $table->foreign('contribution_type_id')
                ->references('id')
                ->on('contribution_types')
                ->cascadeOnDelete();
        });

        Schema::table('contribution_rate_brackets', function (Blueprint $table) {
            $table->dropForeign(['contribution_rate_schedule_id']);
            $table->foreign('contribution_rate_schedule_id')
                ->references('id')
                ->on('contribution_rate_schedules')
                ->cascadeOnDelete();
        });

        Schema::table('contribution_deduction_settings', function (Blueprint $table) {
            $table->dropForeign(['contribution_type_id']);
            $table->foreign('contribution_type_id')
                ->references('id')
                ->on('contribution_types')
                ->cascadeOnDelete();
        });

        Schema::table('payroll_contributions', function (Blueprint $table) {
            $table->dropForeign(['contribution_type_id']);
            $table->dropForeign(['contribution_rate_schedule_id']);

            $table->foreign('contribution_type_id')
                ->references('id')
                ->on('contribution_types')
                ->cascadeOnDelete();

            $table->foreign('contribution_rate_schedule_id')
                ->references('id')
                ->on('contribution_rate_schedules')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('payroll_contributions', function (Blueprint $table) {
            $table->dropForeign(['contribution_type_id']);
            $table->dropForeign(['contribution_rate_schedule_id']);

            $table->foreign('contribution_type_id')
                ->references('id')
                ->on('contribution_types')
                ->restrictOnDelete();

            $table->foreign('contribution_rate_schedule_id')
                ->references('id')
                ->on('contribution_rate_schedules')
                ->restrictOnDelete();
        });

        Schema::table('contribution_deduction_settings', function (Blueprint $table) {
            $table->dropForeign(['contribution_type_id']);
            $table->foreign('contribution_type_id')
                ->references('id')
                ->on('contribution_types')
                ->restrictOnDelete();
        });

        Schema::table('contribution_rate_brackets', function (Blueprint $table) {
            $table->dropForeign(['contribution_rate_schedule_id']);
            $table->foreign('contribution_rate_schedule_id')
                ->references('id')
                ->on('contribution_rate_schedules')
                ->restrictOnDelete();
        });

        Schema::table('contribution_rate_schedules', function (Blueprint $table) {
            $table->dropForeign(['contribution_type_id']);
            $table->foreign('contribution_type_id')
                ->references('id')
                ->on('contribution_types')
                ->restrictOnDelete();
        });
    }
};

