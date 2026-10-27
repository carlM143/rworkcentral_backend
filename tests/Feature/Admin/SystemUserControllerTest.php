<?php

namespace Tests\Feature\Admin;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SystemUserControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_fetch_and_setup_a_pending_employee_account(): void
    {
        $administratorRole = Role::create(['name' => 'Administrator']);
        $employeeRole = Role::create(['name' => 'Employee']);
        $administrator = User::factory()->create(['role_id' => $administratorRole->id]);
        $employee = $this->createEmployee();

        Sanctum::actingAs($administrator);

        $this->getJson("/api/admin/system-users/employees/{$employee->id}/edit")
            ->assertOk()
            ->assertJsonPath('data.user.user_id', null)
            ->assertJsonPath('data.user.employee_id', $employee->id)
            ->assertJsonPath('data.user.account_status', 'pending_setup')
            ->assertJsonPath('data.user.can_setup_account', true);

        $response = $this->postJson(
            "/api/admin/system-users/employees/{$employee->id}/setup-account",
            [
                'email' => 'juan.account@example.com',
                'role_id' => $employeeRole->id,
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ]
        );

        $response
            ->assertCreated()
            ->assertJsonPath('data.employee_id', $employee->id)
            ->assertJsonPath('data.email', 'juan.account@example.com')
            ->assertJsonPath('data.role.id', $employeeRole->id)
            ->assertJsonPath('data.account_status', 'active');

        $user = User::where('email', 'juan.account@example.com')->firstOrFail();

        $this->assertTrue(Hash::check('password123', $user->password));
        $this->assertSame($user->id, $employee->fresh()->user_id);
        $this->assertSame(
            'juan.account@example.com',
            $employee->contactInformation()->value('email')
        );

        $this->postJson('/api/login', [
            'email' => 'juan.account@example.com',
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.role.id', $employeeRole->id)
            ->assertJsonPath('data.token_type', 'Bearer');
    }

    public function test_administrator_can_update_an_existing_employee_account(): void
    {
        $administratorRole = Role::create(['name' => 'Administrator']);
        $employeeRole = Role::create(['name' => 'Employee']);
        $supervisorRole = Role::create(['name' => 'Supervisor']);
        $administrator = User::factory()->create(['role_id' => $administratorRole->id]);
        $employeeUser = User::factory()->create([
            'role_id' => $employeeRole->id,
            'email' => 'juan@example.com',
        ]);
        $employee = $this->createEmployee($employeeUser);
        $employeeUser->createToken('existing-session');

        Sanctum::actingAs($administrator);

        $response = $this->patchJson("/api/admin/system-users/{$employeeUser->id}", [
            'email' => 'juan.updated@example.com',
            'role_id' => $supervisorRole->id,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.user_id', $employeeUser->id)
            ->assertJsonPath('data.email', 'juan.updated@example.com')
            ->assertJsonPath('data.role.id', $supervisorRole->id);

        $employeeUser->refresh();

        $this->assertTrue(Hash::check('newpassword123', $employeeUser->password));
        $this->assertSame($supervisorRole->id, $employeeUser->role_id);
        $this->assertSame(
            'juan.updated@example.com',
            $employee->contactInformation()->value('email')
        );
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    private function createEmployee(?User $user = null): Employee
    {
        $employee = Employee::create([
            'user_id' => $user?->id,
            'emp_id' => random_int(10000000, 99999999),
            'firstname' => 'Juan',
            'lastname' => 'Dela Cruz',
            'age' => 30,
            'gender' => 'Male',
            'birthday' => '1996-01-01',
            'civil_status' => 'Single',
            'status' => true,
        ]);

        $employee->contactInformation()->create([
            'email' => 'juan@example.com',
            'mobile' => '09171234567',
            'residential_address' => 'Manila',
        ]);

        return $employee;
    }
}
