<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();

            // Company
            $table->foreignId('company_id')
                ->constrained('companies')
                ->restrictOnDelete();

            // Admin / HR who created the holiday
            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            // Holiday classification
            $table->foreignId('holiday_type_id')
                ->constrained('holiday_types')
                ->restrictOnDelete();

            // Holiday information
            $table->string('holiday_name');
            $table->date('holiday_date');

            // all_locations / specific_locations
            $table->string('applies_to')
                ->default('all_locations');

            // Active / Inactive
            $table->boolean('status')->default(true);

            $table->timestamps();

            // Indexes
            $table->index([
                'company_id',
                'holiday_date',
                'status',
            ]);

            $table->index([
                'holiday_type_id',
                'holiday_date',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
