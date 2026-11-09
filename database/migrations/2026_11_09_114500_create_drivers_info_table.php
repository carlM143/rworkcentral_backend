<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drivers_info', function (Blueprint $table) {
            $table->id();

            // The employment record (department + Driver position) of the driver.
            $table->foreignId('employment_id')
                ->unique()
                ->constrained('employments')
                ->restrictOnDelete();

            $table->string('licence_no')->unique();
            $table->string('drivers_code')->nullable()->unique();
            $table->date('expire_date');
            $table->string('image_path')->nullable();

            $table->timestamps();

            $table->index('expire_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drivers_info');
    }
};