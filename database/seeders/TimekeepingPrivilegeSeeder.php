<?php

namespace Database\Seeders;

use App\Models\Privilege;
use App\Models\Role;
use Illuminate\Database\Seeder;

class TimekeepingPrivilegeSeeder extends Seeder
{
    public function run(): void
    {
        $privileges = [
            [
                'module' => 'timekeeping',
                'name' => 'view',
                'description' => 'View employee attendance records.',
            ],
            [
                'module' => 'timekeeping',
                'name' => 'import',
                'description' => 'Import attendance from biometric devices.',
            ],
            [
                'module' => 'timekeeping',
                'name' => 'export',
                'description' => 'Export attendance records.',
            ],
            [
                'module' => 'timekeeping',
                'name' => 'correction.request',
                'description' => 'Submit time log correction requests.',
            ],
            [
                'module' => 'timekeeping',
                'name' => 'correction.review',
                'description' => 'Approve or reject time log corrections.',
            ],
            [
                'module' => 'timekeeping',
                'name' => 'adjustment.request',
                'description' => 'Submit late adjustment requests.',
            ],
            [
                'module' => 'timekeeping',
                'name' => 'adjustment.review',
                'description' => 'Approve or reject late adjustments.',
            ],
        ];

        $savedPrivileges = [];

        foreach ($privileges as $item) {
            $privilege = Privilege::firstOrCreate(
                [
                    'module' => $item['module'],
                    'name' => $item['name'],
                ],
                [
                    'description' => $item['description'],
                ]
            );

            $savedPrivileges[$item['name']] = $privilege->id;
        }

        $rolePermissions = [
            'Administrator' => array_keys($savedPrivileges),

            'Human Resources' => array_keys($savedPrivileges),

            'Manager' => [
                'view',
                'correction.request',
                'adjustment.request',
            ],

            'Supervisor' => [
                'view',
                'correction.request',
                'adjustment.request',
            ],

            'Employee' => [
                'view',
                'correction.request',
                'adjustment.request',
            ],
        ];

        foreach ($rolePermissions as $roleName => $permissionNames) {
            $role = Role::query()
                ->where('name', $roleName)
                ->first();

            if (!$role) {
                continue;
            }

            $ids = array_map(
                fn ($name) => $savedPrivileges[$name],
                $permissionNames
            );

            // Adds only missing permissions without removing
            // previously configured role privileges.
            $role->privileges()->syncWithoutDetaching($ids);
        }
    }
}
