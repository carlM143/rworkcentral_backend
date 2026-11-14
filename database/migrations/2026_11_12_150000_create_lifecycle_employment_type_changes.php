<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lifecycle_employment_type_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('employment_id')->constrained()->restrictOnDelete();
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('previous_type_id')->constrained('employment_types')->restrictOnDelete();
            $table->foreignId('new_type_id')->constrained('employment_types')->restrictOnDelete();
            $table->string('previous_type_name');
            $table->string('new_type_name');
            $table->text('reason');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lifecycle_employment_type_changes');
    }
};
