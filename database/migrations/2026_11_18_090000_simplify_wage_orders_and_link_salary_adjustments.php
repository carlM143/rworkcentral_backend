<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // I-check muna kung wala pa ang column (dahil na-run na ito bago nag-fail kanina)
        if (!Schema::hasColumn('wage_orders', 'effective_date')) {
            Schema::table('wage_orders', function (Blueprint $table) {
                $table->date('effective_date')->nullable()->after('title');
            });
        }

        // Keep existing drafts: the order takes the earliest date of its rates.
        if (Schema::hasColumn('wage_order_rates', 'effective_date')) {
            DB::table('wage_orders')->orderBy('id')->each(function ($order) {
                $date = DB::table('wage_order_rates')->where('wage_order_id', $order->id)->min('effective_date');
                DB::table('wage_orders')->where('id', $order->id)->update(['effective_date' => $date ?? now()->toDateString()]);
            });
        }

        // Keep only the first tranche of each classification.
        if (Schema::hasColumn('wage_order_rates', 'tranche_no')) {
            DB::table('wage_order_rates')->where('tranche_no', '>', 1)->delete();
        }

        Schema::table('wage_order_rates', function (Blueprint $table) {
            // 1. I-drop muna ang foreign key para pakawalan ang unique index
            $table->dropForeign(['wage_order_id']);
            
            // 2. I-drop na ang unique index at regular index
            $table->dropUnique('wage_order_rate_unique');
            $table->dropIndex(['classification', 'effective_date']);
        });

        Schema::table('wage_order_rates', function (Blueprint $table) {
            $table->dropColumn(['tranche_no', 'effective_date']);
            
            // 3. Gumawa ng bagong unique index at ibalik ang foreign key
            $table->unique(['wage_order_id', 'classification'], 'wage_order_rate_unique');
            $table->foreign('wage_order_id')->references('id')->on('wage_orders')->cascadeOnDelete();
        });

        Schema::table('salary_adjustments', function (Blueprint $table) {
            $table->foreignId('wage_order_id')->nullable()->after('salary_setup_id')
                ->constrained('wage_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('salary_adjustments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wage_order_id');
        });

        Schema::table('wage_order_rates', function (Blueprint $table) {
            // I-drop din muna ang foreign key sa down() bago alisin ang unique index
            $table->dropForeign(['wage_order_id']);
            $table->dropUnique('wage_order_rate_unique');
            
            $table->unsignedTinyInteger('tranche_no')->default(1);
            $table->date('effective_date')->nullable();
        });

        DB::table('wage_order_rates')
            ->join('wage_orders', 'wage_orders.id', '=', 'wage_order_rates.wage_order_id')
            ->update(['wage_order_rates.effective_date' => DB::raw('wage_orders.effective_date')]);

        Schema::table('wage_order_rates', function (Blueprint $table) {
            $table->unique(['wage_order_id', 'classification', 'tranche_no'], 'wage_order_rate_unique');
            $table->index(['classification', 'effective_date']);
            $table->foreign('wage_order_id')->references('id')->on('wage_orders')->cascadeOnDelete();
        });

        Schema::table('wage_orders', function (Blueprint $table) {
            $table->dropColumn('effective_date');
        });
    }
};