<?php

namespace Tests\Feature\Admin;

use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        Sanctum::actingAs($this->admin);

        $this->company = Company::create([
            'admin_id' => $this->admin->id,
            'company_name' => 'R Work Central',
            'location' => 'Metro Manila, Philippines',
            'status' => true,
        ]);
    }

    public function test_authenticated_user_can_list_companies(): void
    {
        $this->getJson('/api/admin/companies')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.company_name', 'R Work Central');
    }

    public function test_authenticated_user_can_create_a_company(): void
    {
        $this->postJson('/api/admin/companies', [
            'company_name' => 'Second Subsidiary Corp.',
            'location' => 'Cebu City, Philippines',
            'status' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.company_name', 'Second Subsidiary Corp.')
            ->assertJsonPath('data.location', 'Cebu City, Philippines');

        $this->assertDatabaseHas('companies', [
            'company_name' => 'Second Subsidiary Corp.',
            'location' => 'Cebu City, Philippines',
        ]);
    }

    public function test_company_name_must_be_unique(): void
    {
        $this->postJson('/api/admin/companies', [
            'company_name' => 'R Work Central',
            'location' => 'Davao City',
        ])->assertStatus(422)->assertJsonValidationErrors('company_name');
    }

    public function test_authenticated_user_can_get_current_company(): void
    {
        $this->getJson('/api/admin/company/current')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.company_name', 'R Work Central')
            ->assertJsonPath('data.location', 'Metro Manila, Philippines');
    }

    public function test_authenticated_user_can_show_company(): void
    {
        $this->getJson("/api/admin/companies/{$this->company->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.company_name', 'R Work Central');
    }

    public function test_authenticated_user_can_update_company_information(): void
    {
        $this->putJson("/api/admin/companies/{$this->company->id}", [
            'company_name' => 'R Work Central Updated Inc.',
            'location' => 'Makati City, Philippines',
            'status' => true,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.company_name', 'R Work Central Updated Inc.')
            ->assertJsonPath('data.location', 'Makati City, Philippines');

        $this->assertDatabaseHas('companies', [
            'id' => $this->company->id,
            'company_name' => 'R Work Central Updated Inc.',
            'location' => 'Makati City, Philippines',
        ]);
    }

    public function test_update_requires_name_and_location(): void
    {
        $this->putJson("/api/admin/companies/{$this->company->id}", [
            'company_name' => '',
            'location' => '',
        ])->assertStatus(422)->assertJsonValidationErrors(['company_name', 'location']);
    }

    public function test_authenticated_user_can_delete_unused_company(): void
    {
        $newCompany = Company::create([
            'admin_id' => $this->admin->id,
            'company_name' => 'To Be Deleted Corp.',
            'location' => 'Iloilo',
            'status' => true,
        ]);

        $this->deleteJson("/api/admin/companies/{$newCompany->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('companies', ['id' => $newCompany->id]);
    }

    public function test_company_with_departments_cannot_be_deleted(): void
    {
        Department::create([
            'admin_id' => $this->admin->id,
            'company_id' => $this->company->id,
            'department_name' => 'Engineering',
            'department_code' => 'ENG',
            'status' => true,
        ]);

        $this->deleteJson("/api/admin/companies/{$this->company->id}")
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('companies', ['id' => $this->company->id]);
    }
}
