<?php

namespace Tests\Feature\Admin;

use App\Models\Privilege;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeaveApprovalPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_approver_can_load_leave_list_without_final_approval_permission(): void
    {
        $role = Role::create(['name' => 'Department Reviewer']);
        foreach (['leaves.view', 'leaves.edit'] as $name) {
            $privilege = Privilege::firstOrCreate(['module' => 'approvals', 'name' => $name], ['description' => $name]);
            $role->privileges()->attach($privilege->id);
        }
        $user = User::factory()->create(['role_id' => $role->id]);
        Sanctum::actingAs($user);
        $this->getJson('/api/admin/leave-approvals')->assertOk()->assertJsonPath('data', []);
        $this->postJson('/api/admin/leave-approvals/preview', [])->assertForbidden();
        $this->assertFalse($user->hasPrivilege('approvals', 'leaves.approve'));
    }

    public function test_user_without_module_view_permission_cannot_load_leave_list(): void
    {
        $role = Role::create(['name' => 'No leave access']);
        Sanctum::actingAs(User::factory()->create(['role_id' => $role->id]));
        $this->getJson('/api/admin/leave-approvals')->assertForbidden();
    }
}
