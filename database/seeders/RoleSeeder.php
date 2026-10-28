<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Seed the available employee roles.
     */
    public function run(): void
    {
        $roles = [
            'Administrator',
            'Human Resources',
            'Manager',
            'Supervisor',
            'Employee',
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate([
                'name' => $role,
            ]);
        }
    }
}
