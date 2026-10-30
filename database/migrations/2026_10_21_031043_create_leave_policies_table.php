<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $table->foreignId('employment_type_id')->constrained('employment_types')->restrictOnDelete();
            $table->boolean('is_eligible')->default(true);
            $table->boolean('is_paid')->default(false);
            $table->decimal('annual_credits', 8, 2)->default(0);
            $table->unsignedInteger('min_service_months')->default(0);
            $table->boolean('allow_carry_over')->default(false);
            $table->decimal('max_carry_over_days', 8, 2)->default(0);
            $table->boolean('requires_approval')->default(true);
            $table->string('usage_window');
            $table->string('validity_period');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->enum('status', ['pending', 'approved'])->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_policies');
    }
};