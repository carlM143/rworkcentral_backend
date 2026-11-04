
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('late_adjustments', function (Blueprint $table) {
            $table->id();

            // ==========================================
            // RELATIONSHIPS
            // ==========================================

            $table->unsignedBigInteger('time_log_id')->index();
            $table->unsignedBigInteger('employee_id')->index();
            $table->unsignedBigInteger('requested_by')->index();
            $table->unsignedBigInteger('reviewed_by')
                ->nullable()
                ->index();

            // ==========================================
            // ADJUSTMENT TYPE
            // ==========================================

            $table->enum('adjustment_type', [
                'late',
                'undertime',
            ])->default('late');

            // ==========================================
            // ORIGINAL ATTENDANCE
            // ==========================================

            $table->dateTime('original_time_in')->nullable();
            $table->dateTime('original_time_out')->nullable();

            // ==========================================
            // REQUESTED ATTENDANCE
            // ==========================================

            $table->dateTime('requested_time_in')->nullable();
            $table->dateTime('requested_time_out')->nullable();

            // ==========================================
            // ORIGINAL LATE CALCULATIONS
            // ==========================================

            $table->unsignedInteger('raw_late_minutes')
                ->default(0);

            $table->unsignedInteger('grace_period_minutes')
                ->default(0);

            $table->unsignedInteger('chargeable_late_minutes')
                ->default(0);

            // ==========================================
            // LEGACY LATE WAIVER FIELDS
            // ==========================================

            $table->unsignedInteger('requested_waiver_minutes')
                ->default(0);

            $table->unsignedInteger('approved_waiver_minutes')
                ->nullable();

            $table->unsignedInteger('final_late_minutes')
                ->nullable();

            // ==========================================
            // GENERAL ADJUSTMENT MINUTES
            // ==========================================

            $table->unsignedInteger('original_minutes')
                ->nullable();

            $table->unsignedInteger('requested_minutes')
                ->nullable();

            $table->unsignedInteger('approved_minutes')
                ->nullable();

            $table->unsignedInteger('final_undertime_minutes')
                ->nullable();

            // ==========================================
            // REQUEST STATUS
            // ==========================================

            $table->enum('status', [
                'pending',
                'approved',
                'rejected',
            ])->default('pending')->index();

            // ==========================================
            // REASON AND REVIEW
            // ==========================================

            $table->text('reason')->nullable();
            $table->text('review_remarks')->nullable();

            $table->timestamp('reviewed_at')->nullable();

            // ==========================================
            // TIMESTAMPS
            // ==========================================

            $table->timestamps();

            // ==========================================
            // FOREIGN KEYS
            // ==========================================

            $table->foreign('time_log_id')
                ->references('id')
                ->on('time_logs')
                ->restrictOnDelete();

            $table->foreign('employee_id')
                ->references('id')
                ->on('employees')
                ->restrictOnDelete();

            $table->foreign('requested_by')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->foreign('reviewed_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('late_adjustments');
    }
};
