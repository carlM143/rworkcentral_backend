<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->restrictOnDelete();

            $table->string('location_name');
            $table->string('city');
            $table->string('province')->nullable();

            $table->boolean('status')->default(true);

            $table->timestamps();

            $table->unique(['company_id', 'location_name']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
