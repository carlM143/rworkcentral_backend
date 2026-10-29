<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wfh_tracker', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained('employees')
                ->restrictOnDelete();

            $table->foreignId('work_from_home_id')
                ->constrained('work_from_home')
                ->restrictOnDelete();

            $table->date('date_attendance');
            $table->string('doc_path')->nullable();

            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->enum('status', [
                'draft',
                'submitted',
                'accepted',
                'returned',
            ])->default('draft');

            $table->timestamps();

            $table->index([
                'employee_id',
                'date_attendance',
            ], 'wfh_tracker_employee_date_idx');

            $table->index([
                'work_from_home_id',
                'status',
            ], 'wfh_tracker_request_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wfh_tracker');
    }
};
