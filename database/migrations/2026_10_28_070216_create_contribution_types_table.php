<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contribution_types', function (Blueprint $table) {
            $table->id();

            $table->string('contribution_code')->unique();
            $table->string('contribution_name');
            $table->string('provider');
            $table->boolean('is_status')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contribution_types');
    }
};
