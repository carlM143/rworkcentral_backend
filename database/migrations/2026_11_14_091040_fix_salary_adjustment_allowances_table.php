<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('salary_adjustment_allowances');

        Schema::create('salary_adjustment_allowances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_adjustment_id')
                ->constrained('salary_adjustments')
                ->cascadeOnDelete();
            $table->string('allowance_name', 100);
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['salary_adjustment_id', 'allowance_name'], 'sal_adj_allowance_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_adjustment_allowances');
    }
};