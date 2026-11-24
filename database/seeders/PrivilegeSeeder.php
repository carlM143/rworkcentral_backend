<?php

namespace Database\Seeders;

use App\Models\Privilege;
use App\Models\Role;
use App\Support\PrivilegeCatalog;
use Illuminate\Database\Seeder;

/**
 * Syncs config/privileges.php into the `privileges` table and gives the
 * default roles their starting privileges.
 *
 * Safe to run again (php artisan db:seed --class=PrivilegeSeeder):
 *   - new privileges are added, existing ones keep their ids
 *   - Administrator always receives every privilege
 *   - other roles get defaults only while they have no privileges outside
 *     timekeeping, so changes made in Roles & Permissions are kept
 *   - privileges added by this run (a new module or feature) are also given
 *     to the roles whose defaults include them, even customised roles
 */
class PrivilegeSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RoleSeeder::class);

        $ids = [];
        $created = [];

        foreach (PrivilegeCatalog::all() as $row) {
            $privilege = Privilege::updateOrCreate(
                ['module' => $row['module'], 'name' => $row['name']],
                ['description' => $row['description']],
            );

            $ids["{$row['module']}:{$row['name']}"] = $privilege->id;

            if ($privilege->wasRecentlyCreated) {
                $created["{$row['module']}:{$row['name']}"] = $privilege->id;
            }
        }

        foreach (Role::query()->with('privileges:id,module')->get() as $role) {
            if (PrivilegeCatalog::isAdminRole($role->name)) {
                $role->privileges()->syncWithoutDetaching(array_values($ids));
                continue;
            }

            $customised = $role->privileges->contains(fn ($p) => $p->module !== 'timekeeping');
            $patterns = PrivilegeCatalog::defaultsFor($role->name);

            if (! $patterns) {
                continue;
            }

            if ($customised) {
                // Only privileges added by this run that this role gets by default.
                $new = collect($created)
                    ->filter(function ($id, $key) use ($patterns) {
                        [$module, $name] = explode(':', $key, 2);

                        return PrivilegeCatalog::matches($module, $name, $patterns);
                    })
                    ->values()
                    ->all();

                $role->privileges()->syncWithoutDetaching($new);

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
