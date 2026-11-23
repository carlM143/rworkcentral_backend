<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const REQUEST_TABLES = ['leave_requests', 'overtime_requests', 'undertime_requests'];

    public function up(): void
    {
        if (! Schema::hasColumn('departments', 'head_employee_id')) {
            Schema::table('departments', function (Blueprint $table) {
                $table->foreignId('head_employee_id')->nullable()->after('admin_id')
                    ->constrained('employees')->nullOnDelete();
            });
        }

        foreach (self::REQUEST_TABLES as $name) {
            if (Schema::hasColumn($name, 'dept_head_status')) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('dept_head_id')->nullable()->after('employee_id')
                    ->constrained('users')->nullOnDelete();
                $table->string('dept_head_status', 20)->default('skipped')->after('dept_head_id');
                $table->timestamp('dept_head_acted_at')->nullable()->after('dept_head_status');
                $table->text('dept_head_remarks')->nullable()->after('dept_head_acted_at');
                $table->index(['dept_head_id', 'dept_head_status']);
            });
        }
    }

    public function down(): void
    {
        foreach (self::REQUEST_TABLES as $name) {
            if (! Schema::hasColumn($name, 'dept_head_status')) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['dept_head_id', 'dept_head_status']);
                $table->dropConstrainedForeignId('dept_head_id');
                $table->dropColumn(['dept_head_status', 'dept_head_acted_at', 'dept_head_remarks']);
            });
        }

        if (Schema::hasColumn('departments', 'head_employee_id')) {
            Schema::table('departments', function (Blueprint $table) {
                $table->dropConstrainedForeignId('head_employee_id');
            });
        }
    }
};