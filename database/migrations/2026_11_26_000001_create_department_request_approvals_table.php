<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_request_approvals', function (Blueprint $table) {
            $table->id();
            $table->string('request_type', 20);
            $table->unsignedBigInteger('request_id');
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approver_name');
            $table->string('approver_position')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('acted_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->unique(['request_type', 'request_id', 'employee_id'], 'department_request_approvals_unique');
            $table->index(['user_id', 'status'], 'department_request_approvals_user_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_request_approvals');
    }
};
