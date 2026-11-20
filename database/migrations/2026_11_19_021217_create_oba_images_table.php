<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oba_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('oba_request_id')->constrained('oba_requests')->cascadeOnDelete();
            $table->string('image_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oba_images');
    }
};