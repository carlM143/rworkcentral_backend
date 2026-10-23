<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_lifecycle_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('lifecycle_stage_id')->constrained('employee_lifecycle_stages')->restrictOnDelete();
            $table->dateTime('due_date');
            $table->enum('status', ['pending', 'completed'])->default('pending');
            $table->text('remarks')->nullable();
            $table->unique(['employee_id', 'lifecycle_stage_id'], 'emp_lifecycle_unique');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_lifecycle_milestones');
    }
};