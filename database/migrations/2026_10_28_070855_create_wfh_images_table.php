<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wfh_images', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wfh_tracker_id')
                ->constrained('wfh_tracker')
                ->restrictOnDelete();

            $table->string('image_path');

            $table->timestamps();

            $table->index('wfh_tracker_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wfh_images');
    }
};
