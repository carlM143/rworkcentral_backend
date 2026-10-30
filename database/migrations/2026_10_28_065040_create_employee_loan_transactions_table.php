<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_loan_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_loan_id')->constrained('employee_loans')->restrictOnDelete();
            $table->foreignId('payroll_id')->nullable()->constrained('payrolls')->restrictOnDelete();

            $table->enum('transaction_type', [
                'payroll_deduction',
                'manual_payment',
                'adjustment',
                'reversal',
            ]);

            $table->decimal('amount_delta', 12, 2);
            $table->date('transaction_date');
            $table->string('reference_no')->unique();
            $table->text('remarks')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->index(['employee_loan_id', 'transaction_date'], 'loan_txn_date_idx');
            $table->index(['payroll_id', 'transaction_type'], 'loan_txn_payroll_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_loan_transactions');
    }
};
