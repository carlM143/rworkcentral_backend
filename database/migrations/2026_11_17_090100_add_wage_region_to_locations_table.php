<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->string('region_code', 12)->nullable()->after('province');
            $table->string('wage_classification', 50)->default('non_agriculture')->after('region_code');
        });

        DB::table('locations')->whereNull('region_code')->update(['region_code' => 'XI']);
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn(['region_code', 'wage_classification']);
        });
    }
};
