<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lifecycle_employment_type_changes', function (Blueprint $table) {
            $table->date('effective_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lifecycle_employment_type_changes', function (Blueprint $table) {
            $table->dropColumn('effective_date');
        });
    }
};
