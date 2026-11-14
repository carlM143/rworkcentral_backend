<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('employee_lifecycle_milestones', function (Blueprint $table) {
            $table->foreignId('evaluation_document_id')->nullable()->constrained('lifecycle_evaluation_documents')->nullOnDelete();
        });
        Schema::create('employee_offboardings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('Draft');
            $table->json('details');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_offboardings');
        Schema::table('employee_lifecycle_milestones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('evaluation_document_id');
        });
    }
};
