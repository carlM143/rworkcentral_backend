<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withholding_tax_tables', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('frequency', 20);            // daily | weekly | semi_monthly | monthly
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('reference', 150)->nullable(); // e.g. "RR 11-2018, Annex E"
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['frequency', 'effective_from']);
        });

        Schema::create('withholding_tax_brackets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('withholding_tax_table_id')->constrained('withholding_tax_tables')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(1);
            $table->decimal('over', 14, 2);               // taxable pay above this amount
            $table->decimal('up_to', 14, 2)->nullable();  // null = no upper limit
            $table->decimal('base_tax', 14, 2)->default(0);
            $table->decimal('rate', 5, 2)->default(0);    // percent of the excess over "over"
            $table->timestamps();
        });

        Schema::create('payroll_tax_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('apply_mwe_exemption')->default(true);
            $table->boolean('main_allowance_taxable')->default(true);
            $table->json('non_taxable_allowances')->nullable(); // allowance names treated as de minimis
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withholding_tax_brackets');
        Schema::dropIfExists('withholding_tax_tables');
        Schema::dropIfExists('payroll_tax_settings');
    }
};
