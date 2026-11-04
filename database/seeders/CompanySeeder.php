<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    /**
     * Seed the application's companies.
     */
    public function run(): void
    {
        $admin = User::where('email', 'admin@rworkcentral.test')->firstOrFail();

        $company = Company::updateOrCreate(
            ['company_name' => 'R Work Central'],
            [
                'admin_id' => $admin->id,
                'status' => true,
            ],
        );
        $company->locations()->firstOrCreate(
            ['location_name' => 'Metro Manila, Philippines'],
            ['city' => 'Manila', 'status' => true],
        );
    }
}
