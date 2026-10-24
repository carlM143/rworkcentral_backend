<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Seed the application's users.
     */
    public function run(): void
    {
        $administratorRole = Role::where('name', 'Administrator')->firstOrFail();
        $humanResourcesRole = Role::where('name', 'Human Resources')->firstOrFail();

        $users = [
            [
                'role_id' => $administratorRole->id,
                'name' => 'System Administrator',
                'email' => 'admin@rworkcentral.test',
                'password' => 'password',
                'email_verified_at' => now(),
            ],
            [
                'role_id' => $humanResourcesRole->id,
                'name' => 'HR Administrator',
                'email' => 'hr@rworkcentral.test',
                'password' => 'password',
                'email_verified_at' => now(),
            ],
        ];

        foreach ($users as $user) {
            User::updateOrCreate(
                ['email' => $user['email']],
                $user,
            );
        }
    }
}
