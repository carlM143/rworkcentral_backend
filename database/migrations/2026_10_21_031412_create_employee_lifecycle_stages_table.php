<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_lifecycle_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lifecycle_policy_id')->constrained('employee_lifecycle_policies')->cascadeOnDelete();
            $table->string('stage_name');
            $table->string('stage_code');
            $table->unsignedInteger('months_after_hire');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('status')->default(true);
            $table->unique(['lifecycle_policy_id', 'stage_code']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_lifecycle_stages');
    }
};