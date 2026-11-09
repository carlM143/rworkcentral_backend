<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_qr_tokens', function (Blueprint $table) {
            $table->id();

            $table->foreignId('attendance_qr_session_id')
                ->constrained('attendance_qr_sessions')
                ->cascadeOnDelete();

            $table->string('token_hash')->unique();

            $table->dateTime('issued_at');
            $table->dateTime('expires_at');
            $table->dateTime('revoked_at')->nullable();

            $table->timestamps();

            $table->index(['attendance_qr_session_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_qr_tokens');
    }
};