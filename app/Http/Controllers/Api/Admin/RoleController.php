<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Privilege;
use App\Models\Role;
use App\Support\PrivilegeCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $portal = Privilege::query()->where('module', 'portal')->pluck('id', 'name');

        $roles = Role::query()
            ->withCount(['users', 'privileges'])
            ->with('privileges:id')
            ->orderBy('id')
            ->get()
            ->map(fn (Role $role) => $this->summary($role, $portal->all()));

        return response()->json([
            'success' => true,
            'message' => 'Roles retrieved.',
            'data' => $roles,
        ]);
    }

    public function catalog(): JsonResponse
    {
        $ids = Privilege::query()->get(['id', 'module', 'name'])
            ->mapWithKeys(fn ($p) => ["{$p->module}:{$p->name}" => $p->id]);

        $modules = collect(config('privileges.modules', []))
            ->map(function (array $module, string $moduleKey) use ($ids) {
                return [
                    'key' => $moduleKey,
                    'label' => $module['label'],
                    'icon' => $module['icon'] ?? null,
                    // Which portal shows this module: checking it gives that portal access.
                    'portal' => $moduleKey === 'portal' ? null : ($module['portal'] ?? 'admin'),
                    'features' => collect($module['features'])
                        ->map(function (array $feature, string $featureKey) use ($moduleKey, $ids) {
                            $actions = [];
                            foreach (PrivilegeCatalog::actionsOf($featureKey, $feature) as $action => $name) {
                                $actions[$action] = $ids["{$moduleKey}:{$name}"] ?? null;
                            }

                            return [
                                'key' => $featureKey,
                                'label' => $feature['label'],
                                'notes' => $feature['notes'] ?? null,
                                'actions' => $actions,   // action => privilege id (null = not seeded yet)
                            ];
                        })
                        ->values(),
                ];
            })
            ->values();

        $missing = $modules->flatMap(fn ($m) => $m['features'])->flatMap(fn ($f) => $f['actions'])->filter(fn ($id) => $id === null)->count();

        return response()->json([
            'success' => true,
            'message' => $missing ? "{$missing} privilege(s) are not in the database yet. Run: php artisan db:seed --class=PrivilegeSeeder" : 'Privilege catalog retrieved.',
            'data' => [
                'actions' => config('privileges.actions'),
                'modules' => $modules,
                'missing' => $missing,
            ],
        ]);
    }

    public function show(Role $role): JsonResponse
    {
        $role->loadCount(['users', 'privileges'])->load('privileges:id');
        $locked = PrivilegeCatalog::isAdminRole($role->name);

        $users = $role->users()
            ->with('employee:id,user_id,emp_id,firstname,lastname')
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'email'])
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'emp_id' => $u->employee?->emp_id,
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Role retrieved.',
            'data' => $this->summary($role, Privilege::query()->where('module', 'portal')->pluck('id', 'name')->all()) + [
                // Administrator: every privilege, always.
                'privilege_ids' => $locked
                    ? Privilege::query()->pluck('id')->values()
                    : $role->privileges->pluck('id')->values(),
                'users' => $users,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('roles', 'name')],
            'copy_from_role_id' => ['nullable', 'integer', Rule::exists('roles', 'id')],
        ], [
            'name.unique' => 'A role with this name already exists.',
        ]);

        $role = DB::transaction(function () use ($validated) {
            $role = Role::create(['name' => trim($validated['name'])]);

            if (! empty($validated['copy_from_role_id'])) {
                $source = Role::query()->with('privileges:id')->find($validated['copy_from_role_id']);
                $ids = PrivilegeCatalog::isAdminRole($source?->name)
                    ? Privilege::query()->pluck('id')->all()
                    : $source?->privileges->pluck('id')->all() ?? [];
                $role->privileges()->sync($ids);
            }

            return $role;
        });

        return $this->showResponse($role, "Role {$role->name} created.", 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        abort_if(PrivilegeCatalog::isAdminRole($role->name), 409, 'The Administrator role cannot be renamed.');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('roles', 'name')->ignore($role->id)],
        ], [
            'name.unique' => 'A role with this name already exists.',
        ]);

        abort_if(PrivilegeCatalog::isAdminRole($validated['name']), 422, 'This name is reserved.');

        $role->update(['name' => trim($validated['name'])]);

        return $this->showResponse($role, 'Role renamed.');
    }

    public function updatePrivileges(Request $request, Role $role): JsonResponse
    {
        abort_if(PrivilegeCatalog::isAdminRole($role->name), 409, 'The Administrator role always has every privilege.');

        $validated = $request->validate([
            'privilege_ids' => ['present', 'array'],
            'privilege_ids.*' => ['integer', 'distinct', Rule::exists('privileges', 'id')],
        ]);

        $role->privileges()->sync($validated['privilege_ids']);

        return $this->showResponse($role, "Permissions of {$role->name} saved. Users see the change after their next page load.");
    }

    public function destroy(Role $role): JsonResponse
    {
        abort_if(PrivilegeCatalog::isAdminRole($role->name), 409, 'The Administrator role cannot be deleted.');

        $users = $role->users()->count();
        abort_if($users > 0, 409, "Move the {$users} user(s) with this role to another role first.");

        DB::transaction(function () use ($role) {
            $role->privileges()->detach();
            $role->delete();
        });

        return response()->json(['success' => true, 'message' => 'Role deleted.', 'data' => null]);
    }

    // =====================================================
    // HELPERS
    // =====================================================

    /**
     * @param  array<string, int>  $portalIds  portal privilege name => id
     */
    private function summary(Role $role, array $portalIds): array
    {
        $locked = PrivilegeCatalog::isAdminRole($role->name);
        $ids = $role->relationLoaded('privileges') ? $role->privileges->pluck('id') : collect();

        $admin = $locked || (isset($portalIds['admin']) && $ids->contains($portalIds['admin']));
        $employee = isset($portalIds['employee']) && $ids->contains($portalIds['employee']);

        return [
            'id' => $role->id,
            'name' => $role->name,
            'locked' => $locked,
            'users_count' => (int) ($role->users_count ?? $role->users()->count()),
            'privileges_count' => $locked ? Privilege::query()->count() : (int) ($role->privileges_count ?? $ids->count()),
            'portal' => match (true) {
                $admin && $employee => 'Admin & Employee Portal',
                $admin => 'Admin Portal',
                $employee => 'Employee Portal',
                default => 'No portal access',
            },
        ];
    }

    private function showResponse(Role $role, string $message, int $status = 200): JsonResponse
    {
        $response = $this->show($role->fresh());
        $data = $response->getData(true);
        $data['message'] = $message;

        return response()->json($data, $status);
    }
}
