<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_information', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('email');
            $table->string('mobile')->nullable();
            $table->string('residential_address');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_information');
    }
};
