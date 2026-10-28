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

        Company::updateOrCreate(
            ['company_name' => 'R Work Central'],
            [
                'admin_id' => $admin->id,
                'location' => 'Metro Manila, Philippines',
                'status' => true,
            ],
        );
    }
}
