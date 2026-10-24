<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('employment_document_id')->constrained('employment_documents')->cascadeOnDelete();
            $table->string('file_path')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'employment_document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
