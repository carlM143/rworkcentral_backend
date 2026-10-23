<?php

namespace Database\Seeders;

use App\Models\EmploymentType;
use Illuminate\Database\Seeder;

class EmploymentTypeSeeder extends Seeder
{
    /**
     * Seed the available employment types.
     */
    public function run(): void
    {
        $employmentTypes = [
            'Regular',
            'Probationary',
            'Contractual',
            'Part-Time',
        ];

        foreach ($employmentTypes as $employmentType) {
            EmploymentType::updateOrCreate([
                'name' => $employmentType,
            ]);
        }
    }
}
