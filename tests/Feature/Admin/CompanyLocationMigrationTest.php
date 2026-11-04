<?php

namespace Tests\Feature\Admin;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CompanyLocationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_headquarters_is_preserved_before_column_is_removed(): void
    {
        $company = Company::create(['admin_id' => User::factory()->create()->id, 'company_name' => 'Example']);
        $branch = $company->locations()->create(['location_name' => 'Bacaca', 'city' => 'Davao City']);
        Schema::table('companies', fn (Blueprint $table) => $table->string('location')->nullable());
        DB::table('companies')->where('id', $company->id)->update(['location' => 'Metro Manila, Philippines']);

        $migration = require database_path('migrations/2026_11_04_050000_move_company_location_to_locations_table.php');
        $migration->up();
        $migration->up();

        $this->assertFalse(Schema::hasColumn('companies', 'location'));
        $this->assertDatabaseCount('locations', 2);
        $this->assertDatabaseHas('locations', ['company_id' => $company->id, 'location_name' => 'Metro Manila, Philippines', 'city' => 'Manila']);
        $this->assertDatabaseHas('locations', ['id' => $branch->id, 'city' => 'Davao City']);
    }
}
