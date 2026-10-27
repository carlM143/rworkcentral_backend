<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_setups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained('employees')->restrictOnDelete();
            $table->foreignId('salary_type_id')->nullable()->constrained('salary_types')->restrictOnDelete();
            $table->decimal('basic_salary', 12, 2)->nullable();
            $table->decimal('allowance', 12, 2)->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_name')->nullable();
            $table->string('account_number')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_setups');
    }
};
