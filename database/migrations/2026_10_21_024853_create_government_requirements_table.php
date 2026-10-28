<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('government_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('id_name');
            $table->string('id_number');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('government_requirements');
    }
};