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
            'Part-Time',
            'Contractual',
            'Regular',
            'Probationary',
            'Training',
        ];

        foreach ($employmentTypes as $employmentType) {
            EmploymentType::updateOrCreate([
                'name' => $employmentType,
            ]);
        }
    }
}
