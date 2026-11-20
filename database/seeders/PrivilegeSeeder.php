<?php

namespace Database\Seeders;

use App\Models\Privilege;
use App\Models\Role;
use App\Support\PrivilegeCatalog;
use Illuminate\Database\Seeder;

class PrivilegeSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RoleSeeder::class);

        $ids = [];

        foreach (PrivilegeCatalog::all() as $row) {
            $privilege = Privilege::updateOrCreate(
                ['module' => $row['module'], 'name' => $row['name']],
                ['description' => $row['description']],
            );

            $ids["{$row['module']}:{$row['name']}"] = $privilege->id;
        }

        foreach (Role::query()->with('privileges:id,module')->get() as $role) {
            if (PrivilegeCatalog::isAdminRole($role->name)) {
                $role->privileges()->syncWithoutDetaching(array_values($ids));
                continue;
            }

            $customised = $role->privileges->contains(fn ($p) => $p->module !== 'timekeeping');
            $patterns = PrivilegeCatalog::defaultsFor($role->name);

            if ($customised || ! $patterns) {
                continue;
            }

            $defaults = collect($ids)
                ->filter(function ($id, $key) use ($patterns) {
                    [$module, $name] = explode(':', $key, 2);

                    return PrivilegeCatalog::matches($module, $name, $patterns);
                })
                ->values()
                ->all();

            $role->privileges()->syncWithoutDetaching($defaults);
        }
    }
}
