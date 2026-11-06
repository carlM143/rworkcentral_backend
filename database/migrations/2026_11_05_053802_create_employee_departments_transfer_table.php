
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_departments_transfer', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained('employees')
                ->restrictOnDelete();

            $table->foreignId('from_department_id')
                ->constrained('departments')
                ->restrictOnDelete();

            $table->foreignId('to_department_id')
                ->constrained('departments')
                ->restrictOnDelete();

            $table->date('effective_date');
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending');

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_remarks')->nullable();

            $table->foreignId('applied_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('applied_at')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['effective_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_departments_transfer');
    }
};
