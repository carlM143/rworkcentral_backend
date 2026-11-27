<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $old = DB::table('privileges')->where('module', 'approvals')
            ->whereIn('name', ['first_level.view', 'first_level.approve'])->pluck('id');
        foreach (['approvals' => ['leaves', 'overtime', 'undertime', 'oba'], 'wfh' => ['requests']] as $module => $features) {
            foreach ($features as $feature) {
                foreach (['view', 'edit'] as $action) {
                    $sourceIds = DB::table('privileges')->where('module', 'approvals')
                        ->whereIn('name', $action === 'edit' ? ['first_level.approve'] : ['first_level.view', 'first_level.approve'])->pluck('id');
                    $roles = DB::table('role_privileges')->whereIn('privilege_id', $sourceIds)->pluck('role_id')->unique();
                    $name = "{$feature}.{$action}";
                    DB::table('privileges')->updateOrInsert(['module' => $module, 'name' => $name], [
                        'description' => "{$feature}: {$action} (department approval)", 'updated_at' => now(),
                    ]);
                    $id = DB::table('privileges')->where('module', $module)->where('name', $name)->value('id');
                    foreach ($roles as $role) {
                        DB::table('role_privileges')->updateOrInsert(['role_id' => $role, 'privilege_id' => $id], []);
                    }
                }
            }
        }
        DB::table('role_privileges')->whereIn('privilege_id', $old)->delete();
        DB::table('privileges')->whereIn('id', $old)->delete();
    }

    public function down(): void
    {
        // Role assignments may have been customized since migration; preserve them.
    }
};
