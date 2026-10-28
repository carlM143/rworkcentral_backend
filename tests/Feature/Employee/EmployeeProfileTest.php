<?php

namespace Tests\Feature\Employee;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeProfileTest extends TestCase
{
    use RefreshDatabase;

    private function createEmployeeUser(): array
    {
        $admin = User::factory()->create();
        $company = Company::create([
            'admin_id' => $admin->id,
            'company_name' => 'Acme Corp',
            'location' => 'HQ',
            'status' => true,
        ]);

        $role = Role::create(['name' => 'Employee']);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'password' => Hash::make('password123'),
        ]);

        $department = Department::create([
            'admin_id' => $admin->id,
            'company_id' => $company->id,
            'department_name' => 'Operations',
            'department_code' => 'OPS',
            'status' => true,
        ]);

        $employee = Employee::create([
            'user_id' => $user->id,
            'department_id' => $department->id,
            'emp_id' => 10001,
            'firstname' => 'Juan',
            'lastname' => 'Dela Cruz',
            'age' => 28,
            'gender' => 'Male',
            'birthday' => '1998-05-15',
            'civil_status' => 'Single',
            'status' => true,
        ]);

        $employee->contactInformation()->create([
            'email' => $user->email,
            'mobile' => '09171234567',
            'residential_address' => 'Quezon City',
        ]);

        return [$user, $employee];
    }

    public function test_employee_can_fetch_their_own_profile(): void
    {
        [$user, $employee] = $this->createEmployeeUser();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/employee/profile');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $employee->id)
            ->assertJsonPath('data.firstname', 'Juan')
            ->assertJsonPath('data.department.department_name', 'Operations');
    }

    public function test_employee_can_update_their_contact_information(): void
    {
        [$user, $employee] = $this->createEmployeeUser();
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/employee/profile/contact', [
            'mobile' => '09187654321',
            'residential_address' => 'Makati City',
            'emergency_name' => 'Maria Dela Cruz',
            'emergency_relationship' => 'Spouse',
            'emergency_phone' => '09199998888',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.contact_information.mobile', '09187654321')
            ->assertJsonPath('data.contact_information.residential_address', 'Makati City');

        $this->assertDatabaseHas('contact_information', [
            'employee_id' => $employee->id,
            'mobile' => '09187654321',
        ]);

        $this->assertDatabaseHas('contact_person', [
            'employee_id' => $employee->id,
            'name' => 'Maria Dela Cruz',
        ]);
    }

    public function test_employee_can_update_their_password(): void
    {
        [$user] = $this->createEmployeeUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/employee/profile/password', [
            'current_password' => 'password123',
            'password' => 'newpassword456',
            'password_confirmation' => 'newpassword456',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue(Hash::check('newpassword456', $user->fresh()->password));
    }

    public function test_password_update_fails_with_incorrect_current_password(): void
    {
        [$user] = $this->createEmployeeUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/employee/profile/password', [
            'current_password' => 'wrongpassword',
            'password' => 'newpassword456',
            'password_confirmation' => 'newpassword456',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_employee_can_upload_profile_photo_via_file(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        [$user, $employee] = $this->createEmployeeUser();
        Sanctum::actingAs($user);

        $file = \Illuminate\Http\UploadedFile::fake()->image('avatar.jpg', 400, 400);

        $response = $this->postJson('/api/employee/profile/photo', [
            'photo' => $file,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $fresh = $employee->fresh();
        $this->assertNotNull($fresh->profile_photo);
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists($fresh->profile_photo);
    }

    public function test_employee_can_upload_profile_photo_via_base64(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        [$user, $employee] = $this->createEmployeeUser();
        Sanctum::actingAs($user);

        // 1x1 transparent png
        $base64 = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

        $response = $this->postJson('/api/employee/profile/photo', [
            'photo_base64' => $base64,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $fresh = $employee->fresh();
        $this->assertNotNull($fresh->profile_photo);
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists($fresh->profile_photo);
    }

    public function test_employee_can_delete_profile_photo(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        [$user, $employee] = $this->createEmployeeUser();
        Sanctum::actingAs($user);

        $file = \Illuminate\Http\UploadedFile::fake()->image('avatar.jpg', 300, 300);
        $this->postJson('/api/employee/profile/photo', ['photo' => $file]);

        $this->assertNotNull($employee->fresh()->profile_photo);

        $response = $this->deleteJson('/api/employee/profile/photo');
        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNull($employee->fresh()->profile_photo);
    }
}
