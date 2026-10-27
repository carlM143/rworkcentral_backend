<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_credit_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('leave_policy_id')->constrained('leave_policies')->restrictOnDelete();
            $table->foreignId('leave_request_id')->nullable()->constrained('leave_requests')->restrictOnDelete();

            $table->enum('transaction_type', [
                'allocation',
                'deduction',
                'adjustment',
                'carry_over',
                'expiration',
                'reversal'
            ]);

            $table->decimal('days_delta', 8, 2);

            $table->date('cycle_start');
            $table->date('cycle_end');
            $table->date('effective_date');

            $table->string('reference_key', 150)->unique();
            $table->text('remarks')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['employee_id', 'leave_policy_id', 'cycle_start', 'cycle_end'], 'leave_credit_emp_policy_cycle_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_credit_transactions');
    }
};
