<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets HR deactivate a driver (resigned, reassigned, suspended) without
 * deleting their trip history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers_info', function (Blueprint $table) {
            $table->string('status')->default('active')->after('image_path'); // active | inactive
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('drivers_info', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn('status');
        });
    }
};