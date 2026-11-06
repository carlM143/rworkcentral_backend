<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wfh_tracker', function (Blueprint $table) {
            // HR note when a day is returned (or optional note on accept).
            $table->text('remarks')->nullable()->after('status');
            $table->dateTime('submitted_at')->nullable()->after('remarks');
            $table->dateTime('reviewed_at')->nullable()->after('submitted_at');

            // One tracker row per WFH request per day.
            $table->unique(
                ['work_from_home_id', 'date_attendance'],
                'wfh_tracker_request_date_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('wfh_tracker', function (Blueprint $table) {
            $table->dropUnique('wfh_tracker_request_date_unique');
            $table->dropColumn(['remarks', 'submitted_at', 'reviewed_at']);
        });
    }
};