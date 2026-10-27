<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('employment_type_id')->constrained('employment_types')->restrictOnDelete();
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('position_id')->constrained('positions')->restrictOnDelete();
            $table->foreignId('work_schedule_id')->nullable()->constrained('work_schedules')->restrictOnDelete();
            $table->foreignId('restday_id')->nullable()->constrained('rest_days')->restrictOnDelete();
            $table->date('date_hired');
            $table->string('mobile')->nullable();
            $table->string('residential_address')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employments');
    }
};
