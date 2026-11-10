<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wfh_tracker', function (Blueprint $table) {
            if (! Schema::hasColumn('wfh_tracker', 'remarks')) {
                $table->text('remarks')->nullable()->after('status');
            }
            if (! Schema::hasColumn('wfh_tracker', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('remarks');
            }
            if (! Schema::hasColumn('wfh_tracker', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('submitted_at');
            }
        });

        Schema::table('wfh_images', function (Blueprint $table) {
            if (! Schema::hasColumn('wfh_images', 'original_name')) {
                $table->string('original_name')->nullable()->after('image_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('wfh_tracker', function (Blueprint $table) {
            $columns = array_filter(
                ['remarks', 'submitted_at', 'reviewed_at'],
                fn (string $col) => Schema::hasColumn('wfh_tracker', $col)
            );
            if ($columns) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('wfh_images', function (Blueprint $table) {
            if (Schema::hasColumn('wfh_images', 'original_name')) {
                $table->dropColumn('original_name');
            }
        });
    }
};
