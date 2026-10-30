<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained('employees')
                ->restrictOnDelete();

            $table->foreignId('leave_type_id')
                ->constrained('leave_types')
                ->restrictOnDelete();

            $table->foreignId('leave_request_id')
                ->nullable()
                ->constrained('leave_requests')
                ->nullOnDelete();

            $table->foreignId('leave_policy_id')
                ->nullable()
                ->constrained('leave_policies')
                ->restrictOnDelete();

            $table->string('request_no')->unique();
            $table->date('request_date');

            $table->enum('requested_pay_type', [
                'paid',
                'unpaid',
                'mixed',
            ]);

            $table->enum('approved_pay_type', [
                'paid',
                'unpaid',
                'mixed',
            ])->nullable();

            $table->text('reason')->nullable();

            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('approved_at')->nullable();
            $table->string('file_path')->nullable();

            $table->enum('status', [
                'pending',
                'approved',
                'rejected',
                'cancelled',
            ])->default('pending');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
