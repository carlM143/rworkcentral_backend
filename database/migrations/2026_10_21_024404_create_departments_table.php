<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('admin_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->restrictOnDelete();

            $table->string('department_name');
            $table->string('department_code', 50);
            $table->boolean('status')->default(true);

            $table->timestamps();

            $table->unique(['company_id', 'department_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
