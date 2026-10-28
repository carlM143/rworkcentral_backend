<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holiday_companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holiday_id')->constrained('holidays')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unique(['holiday_id', 'company_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_companies');
    }
};