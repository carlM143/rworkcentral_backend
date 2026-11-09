<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_qr_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('name');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            // active, ended, revoked
            $table->string('status')->default('active');

            $table->dateTime('revoked_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_qr_sessions');
    }
};