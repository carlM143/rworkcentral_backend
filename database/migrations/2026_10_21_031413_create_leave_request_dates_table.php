<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_request_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained('leave_requests')->cascadeOnDelete();
            $table->date('leave_date');
            $table->enum('day_type', ['whole_day', 'half_day']);
            $table->decimal('day_value', 3, 2)->default(1.00);
            $table->enum('requested_pay_type', ['paid', 'unpaid'])->nullable();
            $table->enum('approved_pay_type', ['paid', 'unpaid'])->nullable();
            $table->unique(['leave_request_id', 'leave_date']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_request_dates');
    }
};
