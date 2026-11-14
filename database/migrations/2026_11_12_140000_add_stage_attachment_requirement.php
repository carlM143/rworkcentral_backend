<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('employee_lifecycle_stages', function (Blueprint $table) {
            $table->boolean('requires_attachment')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('employee_lifecycle_stages', fn (Blueprint $table) => $table->dropColumn('requires_attachment'));
    }
};
