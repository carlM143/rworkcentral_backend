<?php

namespace Tests\Feature\Admin;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeLifecycleMilestone;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LifecycleConfigurationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        foreach ([
            '0001_01_00_000000_create_roles_table.php',
            '0001_01_01_000000_create_users_table.php',
            '2026_10_21_024400_create_companies_table.php',
            '2026_10_21_024404_create_departments_table.php',
            '2026_10_21_024848_create_employees_table.php',
            '2026_10_21_025210_create_employment_types_table.php',
            '2026_10_21_031411_create_employee_lifecycle_policies_table.php',
            '2026_10_21_031412_create_employee_lifecycle_stages_table.php',
            '2026_10_21_031413_create_employee_lifecycle_milestones_table.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
    }

    public function test_administrator_manages_configuration_without_deleting_history(): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'Administrator']);
        $admin = User::factory()->create(['role_id' => $adminRole->id]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->getJson('/api/admin/lifecycle/policies')->assertForbidden();
        $this->postJson('/api/admin/lifecycle/policies', [])->assertForbidden();

        Sanctum::actingAs($admin);
        $policy = $this->postJson('/api/admin/lifecycle/policies', ['name' => 'Probation', 'status' => true])->assertOk()->json('data.id');
        $this->putJson("/api/admin/lifecycle/policies/{$policy}", ['name' => 'Probation review', 'status' => true])->assertOk()->assertJsonPath('data.name', 'Probation review');
        $stageData = ['lifecycle_policy_id' => $policy, 'stage_name' => 'Review', 'stage_code' => 'REVIEW', 'months_after_hire' => 3, 'sort_order' => 1, 'status' => true];
        $stage = $this->postJson('/api/admin/lifecycle/stages', $stageData)->assertOk()->json('data.id');
        $this->postJson('/api/admin/lifecycle/stages', $stageData)->assertUnprocessable();
        $this->deleteJson("/api/admin/lifecycle/policies/{$policy}")->assertUnprocessable();
        $company = Company::create(['admin_id' => $admin->id, 'company_name' => 'Example']);
        $department = Department::create(['admin_id' => $admin->id, 'company_id' => $company->id, 'department_name' => 'Operations', 'department_code' => 'OPS', 'status' => true]);
        $employee = Employee::create(['user_id' => $user->id, 'department_id' => $department->id, 'emp_id' => 12345678, 'firstname' => 'First', 'lastname' => 'Employee', 'birthday' => '1990-01-01', 'status' => true]);
        EmployeeLifecycleMilestone::create(['employee_id' => $employee->id, 'lifecycle_stage_id' => $stage, 'due_date' => '2026-12-01', 'status' => 'pending']);
        $this->deleteJson("/api/admin/lifecycle/stages/{$stage}")->assertUnprocessable();
        $this->getJson('/api/admin/lifecycle/stages')->assertOk()->assertJsonPath('data.0.milestones_count', 1);
    }
}
