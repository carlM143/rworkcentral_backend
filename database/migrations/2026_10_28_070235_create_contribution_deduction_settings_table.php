<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contribution_deduction_settings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->restrictOnDelete();

            $table->foreignId('contribution_type_id')
                ->constrained('contribution_types')
                ->restrictOnDelete();

            $table->decimal('first_cutoff_percent', 5, 2)->default(0);
            $table->decimal('second_cutoff_percent', 5, 2)->default(0);

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->boolean('is_status')->default(true);

            $table->timestamps();

            $table->index([
                'company_id',
                'contribution_type_id',
                'effective_from',
            ], 'contribution_deduction_effective_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contribution_deduction_settings');
    }
};
