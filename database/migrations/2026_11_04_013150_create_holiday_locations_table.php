<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holiday_locations', function (Blueprint $table) {
            $table->id();

            // Holiday
            $table->foreignId('holiday_id')
                ->constrained('holidays')
                ->cascadeOnDelete();

            // Work Location
            $table->foreignId('location_id')
                ->constrained('locations')
                ->restrictOnDelete();

            // Prevent duplicate holiday-location assignments
            $table->unique([
                'holiday_id',
                'location_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_locations');
    }
};
