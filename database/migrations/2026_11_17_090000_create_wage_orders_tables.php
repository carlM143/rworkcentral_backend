<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wage_orders', function (Blueprint $table) {
            $table->id();
            $table->string('region_code', 12);
            $table->string('order_no', 50)->unique();
            $table->string('title');
            $table->date('issued_date')->nullable();
            $table->date('published_date')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('source')->nullable();
            $table->text('remarks')->nullable();
            $table->string('document_path')->nullable();
            $table->string('document_name')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['region_code', 'status']);
        });

        Schema::create('wage_order_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wage_order_id')->constrained('wage_orders')->cascadeOnDelete();
            $table->string('classification', 50);
            $table->unsignedTinyInteger('tranche_no')->default(1);
            $table->date('effective_date');
            $table->decimal('daily_rate', 10, 2);
            $table->decimal('cola', 10, 2)->default(0);
            $table->decimal('previous_rate', 10, 2)->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['wage_order_id', 'classification', 'tranche_no'], 'wage_order_rate_unique');
            $table->index(['classification', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wage_order_rates');
        Schema::dropIfExists('wage_orders');
    }
};