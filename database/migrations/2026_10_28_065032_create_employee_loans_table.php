<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_loans', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained('employees')
                ->restrictOnDelete();

            $table->foreignId('loan_type_id')
                ->constrained('loan_types')
                ->restrictOnDelete();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('loan_reference_no')->nullable();

            $table->decimal('principal_amount', 12, 2);
            $table->decimal('total_payable_amount', 12, 2);
            $table->decimal('opening_balance', 12, 2);
            $table->decimal('deduction_amount', 12, 2);

            $table->date('loan_date')->nullable();
            $table->date('deduction_start_date');

            $table->text('remarks')->nullable();
            $table->boolean('status')->default(true);

            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['loan_type_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_loans');
    }
};
