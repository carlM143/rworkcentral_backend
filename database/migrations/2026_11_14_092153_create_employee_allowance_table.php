<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_allowance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_setup_id')
                ->constrained('salary_setups')
                ->cascadeOnDelete();
            $table->string('allowance_name', 100);
            $table->decimal('allowance', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['salary_setup_id', 'allowance_name'], 'emp_allowance_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_allowance');
    }
};