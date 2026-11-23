<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supervisor (first-level) approval for Work From Home requests, same
 * columns as leave, overtime and undertime requests.
 * Existing WFH requests are "skipped" so they keep working as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('work_from_home', 'dept_head_status')) {
            return;
        }

        Schema::table('work_from_home', function (Blueprint $table) {
            $table->foreignId('dept_head_id')->nullable()->after('employee_id')
                ->constrained('users')->nullOnDelete();
            $table->string('dept_head_status', 20)->default('skipped')->after('dept_head_id');
            $table->timestamp('dept_head_acted_at')->nullable()->after('dept_head_status');
            $table->text('dept_head_remarks')->nullable()->after('dept_head_acted_at');
            $table->index(['dept_head_id', 'dept_head_status']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('work_from_home', 'dept_head_status')) {
            return;
        }

        Schema::table('work_from_home', function (Blueprint $table) {
            $table->dropIndex(['dept_head_id', 'dept_head_status']);
            $table->dropConstrainedForeignId('dept_head_id');
            $table->dropColumn(['dept_head_status', 'dept_head_acted_at', 'dept_head_remarks']);
        });
    }
};