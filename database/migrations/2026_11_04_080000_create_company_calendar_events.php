<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('company_calendar_events')) Schema::create('company_calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->date('event_date');
            $table->time('event_time')->nullable();
            $table->text('description')->nullable();
            $table->string('applies_to')->default('all_locations');
            $table->timestamps();
            $table->index(['company_id', 'event_date']);
        });
        if (! Schema::hasTable('company_calendar_event_locations')) Schema::create('company_calendar_event_locations', function (Blueprint $table) {
            $table->unsignedBigInteger('company_calendar_event_id');
            $table->unsignedBigInteger('location_id');
            $table->primary(['company_calendar_event_id', 'location_id'], 'calendar_event_location_primary');
        });
        $foreignKeys = collect(Schema::getForeignKeys('company_calendar_event_locations'))->pluck('name')->all();
        Schema::table('company_calendar_event_locations', function (Blueprint $table) use ($foreignKeys) {
            if (! in_array('calendar_event_location_event_fk', $foreignKeys, true)) {
                $table->foreign('company_calendar_event_id', 'calendar_event_location_event_fk')->references('id')->on('company_calendar_events')->cascadeOnDelete();
            }
            if (! in_array('calendar_event_location_location_fk', $foreignKeys, true)) {
                $table->foreign('location_id', 'calendar_event_location_location_fk')->references('id')->on('locations')->restrictOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_calendar_event_locations');
        Schema::dropIfExists('company_calendar_events');
    }
};
