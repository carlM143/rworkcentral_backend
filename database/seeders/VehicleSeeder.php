<?php

namespace Database\Seeders;

use App\Models\Vehicle;
use Illuminate\Database\Seeder;

/**
 * Company vehicles used by trip tickets.
 * Safe to run more than once (updateOrCreate by plate number).
 */
class VehicleSeeder extends Seeder
{
    public function run(): void
    {
        $vehicles = [
            ['plate_no' => 'ABC-1234', 'description' => 'Toyota Hiace', 'vehicle_type' => 'Van', 'capacity' => 12],
            ['plate_no' => 'XYZ-9988', 'description' => 'Isuzu Elf', 'vehicle_type' => 'Truck', 'capacity' => 3],
            ['plate_no' => 'LMN-7601', 'description' => 'Ford Transit', 'vehicle_type' => 'Van', 'capacity' => 15],
            ['plate_no' => 'TUV-2345', 'description' => 'Hino Van', 'vehicle_type' => 'Van', 'capacity' => 9],
            ['plate_no' => 'DEF-5441', 'description' => 'Mercedes Sprinter', 'vehicle_type' => 'Van', 'capacity' => 14],
            ['plate_no' => 'MC-4410', 'description' => 'Honda Click', 'vehicle_type' => 'Motorcycle', 'capacity' => 1],
        ];

        foreach ($vehicles as $vehicle) {
            Vehicle::query()->updateOrCreate(
                ['plate_no' => $vehicle['plate_no']],
                $vehicle + ['status' => 'active'],
            );
        }

        $this->command?->info('Vehicles seeded.');
    }
}