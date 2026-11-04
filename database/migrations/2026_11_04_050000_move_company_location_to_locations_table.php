<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('companies', 'location')) {
            return;
        }

        DB::transaction(function () {
            foreach (DB::table('companies')->get() as $company) {
                $name = trim($company->location ?? '');
                if ($name === '' || DB::table('locations')->where('company_id', $company->id)->where('location_name', $name)->exists()) {
                    continue;
                }

                // Confirmed city for the existing headquarters. Other legacy
                // addresses must be resolved before their source is removed.
                if ($name !== 'Metro Manila, Philippines') {
                    throw new RuntimeException("Create a location with a city for company {$company->id}: {$name} before migrating.");
                }

                DB::table('locations')->insert([
                    'company_id' => $company->id,
                    'location_name' => $name,
                    'city' => 'Manila',
                    'province' => null,
                    'status' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('location'));
    }

    public function down(): void
    {
        if (! Schema::hasColumn('companies', 'location')) {
            Schema::table('companies', fn (Blueprint $table) => $table->string('location')->nullable());
        }
        // Retain all locations and employee references when rolling back.
    }
};
