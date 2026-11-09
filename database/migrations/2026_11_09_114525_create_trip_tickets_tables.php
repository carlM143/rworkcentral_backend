<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('plate_no')->unique();
            $table->string('description');
            $table->string('vehicle_type')->nullable();
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('trip_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_no')->unique();

            // Who asked for the trip
            $table->foreignId('requesting_department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('employees')->restrictOnDelete();

            // Plan
            $table->date('trip_date');
            $table->time('scheduled_departure');
            $table->time('expected_return');

            // Assignment
            $table->foreignId('driver_id')->constrained('drivers_info')->restrictOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles')->restrictOnDelete();
            $table->string('destination');
            $table->string('purpose');

            // Actual
            $table->time('departed_at')->nullable();
            $table->time('returned_at')->nullable();
            $table->unsignedInteger('odometer_out')->nullable();
            $table->unsignedInteger('odometer_in')->nullable();

            $table->string('status')->default('scheduled');
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['trip_date', 'status']);
            $table->index(['driver_id', 'trip_date']);
            $table->index(['vehicle_id', 'trip_date']);
        });

        Schema::create('trip_ticket_passengers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_ticket_id')->constrained('trip_tickets')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['trip_ticket_id', 'employee_id'], 'trip_ticket_passenger_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_ticket_passengers');
        Schema::dropIfExists('trip_tickets');
        Schema::dropIfExists('vehicles');
    }
};