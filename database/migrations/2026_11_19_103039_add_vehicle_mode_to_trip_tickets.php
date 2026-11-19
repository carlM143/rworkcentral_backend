<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_tickets', function (Blueprint $table) {
            $table->string('vehicle_mode', 20)->default('company')->after('expected_return');
            $table->string('vehicle_other', 100)->nullable()->after('vehicle_mode');
            $table->foreignId('approved_by')->nullable()->after('requested_by')
                ->constrained('employees')->nullOnDelete();

            // Non-company transport has no driver or company vehicle.
            $table->unsignedBigInteger('driver_id')->nullable()->change();
            $table->unsignedBigInteger('vehicle_id')->nullable()->change();
        });

        Schema::table('drivers_info', function (Blueprint $table) {
            $table->string('licence_no')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('trip_tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['vehicle_mode', 'vehicle_other']);
        });
    }
};