<?php

namespace Tests\Feature\Admin;

use App\Models\ContributionRateSchedule;
use App\Models\ContributionType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContributionTypeTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    private function makeContributionType(array $attributes = []): ContributionType
    {
        return ContributionType::create(array_merge([
            'contribution_code' => 'SSS',
            'contribution_name' => 'Social Security System',
            'provider' => 'SSS',
            'is_status' => true,
        ], $attributes));
    }

    public function test_authenticated_user_can_list_contribution_types(): void
    {
        $this->actingAsAdmin();
        $this->makeContributionType();

        $this->getJson('/api/admin/contribution-types')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.contribution_code', 'SSS')
            ->assertJsonPath('data.0.is_status', true);
    }

    public function test_contribution_type_index_requires_authentication(): void
    {
        $this->getJson('/api/admin/contribution-types')->assertUnauthorized();
    }

    public function test_authenticated_user_can_create_a_contribution_type(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/contribution-types', [
            'contribution_code' => 'PHIC',
            'contribution_name' => 'PhilHealth Premium',
            'provider' => 'PhilHealth',
            'is_status' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.contribution_code', 'PHIC')
            ->assertJsonPath('data.contribution_name', 'PhilHealth Premium');

        $this->assertDatabaseHas('contribution_types', [
            'contribution_code' => 'PHIC',
            'provider' => 'PhilHealth',
        ]);
    }

    public function test_contribution_code_must_be_unique(): void
    {
        $this->actingAsAdmin();
        $this->makeContributionType();

        $this->postJson('/api/admin/contribution-types', [
            'contribution_code' => 'SSS',
            'contribution_name' => 'Duplicate',
            'provider' => 'SSS',
        ])->assertStatus(422)->assertJsonValidationErrors('contribution_code');
    }

    public function test_authenticated_user_can_update_a_contribution_type(): void
    {
        $this->actingAsAdmin();
        $type = $this->makeContributionType();

        $this->putJson("/api/admin/contribution-types/{$type->id}", [
            'contribution_code' => 'SSS',
            'contribution_name' => 'Social Security System (Updated)',
            'provider' => 'SSS',
            'is_status' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.contribution_name', 'Social Security System (Updated)')
            ->assertJsonPath('data.is_status', false);

        $this->assertDatabaseHas('contribution_types', [
            'id' => $type->id,
            'contribution_name' => 'Social Security System (Updated)',
            'is_status' => false,
        ]);
    }

    public function test_contribution_type_can_be_deleted_when_unused(): void
    {
        $this->actingAsAdmin();
        $type = $this->makeContributionType();

        $this->deleteJson("/api/admin/contribution-types/{$type->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('contribution_types', ['id' => $type->id]);
    }

    public function test_contribution_type_in_use_cannot_be_deleted(): void
    {
        $this->actingAsAdmin();
        $type = $this->makeContributionType();

        ContributionRateSchedule::create([
            'contribution_type_id' => $type->id,
            'calculation_method' => 'percentage',
            'salary_basis' => 'monthly_basic_salary',
            'employee_rate' => 4.5,
            'employer_rate' => 9.5,
            'effective_from' => '2026-01-01',
            'is_status' => true,
        ]);

        $this->deleteJson("/api/admin/contribution-types/{$type->id}")
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('contribution_types', ['id' => $type->id]);
    }
}
