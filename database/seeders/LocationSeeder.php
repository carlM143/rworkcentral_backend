<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    /**
     * Seed the company's locations.
     */
    public function run(): void
    {
        $company = Company::where('company_name', 'R Work Central')->firstOrFail();

        $locations = [
            [
                'location_name' => 'Pasig',
                'city' => 'Manila',
            ],
            [
                'location_name' => 'Elrio',
                'city' => 'Davao',
            ],
        ];

        foreach ($locations as $location) {
            $company->locations()->updateOrCreate(
                ['location_name' => $location['location_name']],
                [
                    'city' => $location['city'],
                    'province' => null,
                    'status' => true,
                ],
            );
        }
    }
}