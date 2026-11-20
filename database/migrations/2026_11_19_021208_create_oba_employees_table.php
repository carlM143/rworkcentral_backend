<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oba_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('oba_request_id')->constrained('oba_requests')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('employee_name');
            $table->timestamps();

            $table->unique(['oba_request_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oba_employees');
    }
};