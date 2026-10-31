<?php

namespace Tests\Feature\Admin;

use App\Models\Company;
use App\Models\ContributionDeductionSetting;
use App\Models\ContributionType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContributionDeductionSettingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private ContributionType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create();
        Sanctum::actingAs($admin);

        $this->company = Company::create([
            'admin_id' => $admin->id,
            'company_name' => 'R Work Central',
            'location' => 'Manila',
            'status' => true,
        ]);

        $this->type = ContributionType::create([
            'contribution_code' => 'SSS',
            'contribution_name' => 'Social Security System',
            'provider' => 'SSS',
            'is_status' => true,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'company_id' => $this->company->id,
            'contribution_type_id' => $this->type->id,
            'first_cutoff_percent' => 50.00,
            'second_cutoff_percent' => 50.00,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'is_status' => true,
        ], $overrides);
    }

    public function test_authenticated_user_can_list_deduction_settings(): void
    {
        ContributionDeductionSetting::create($this->payload());

        $this->getJson('/api/admin/contribution-deduction-settings')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.company.company_name', 'R Work Central')
            ->assertJsonPath('data.0.contribution_type.contribution_code', 'SSS')
            ->assertJsonPath('data.0.first_cutoff_percent', '50.00');
    }

    public function test_authenticated_user_can_create_a_deduction_setting(): void
    {
        $this->postJson('/api/admin/contribution-deduction-settings', $this->payload())
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.company_id', $this->company->id)
            ->assertJsonPath('data.first_cutoff_percent', '50.00');

        $this->assertDatabaseHas('contribution_deduction_settings', [
            'company_id' => $this->company->id,
            'contribution_type_id' => $this->type->id,
            'first_cutoff_percent' => 50.00,
            'second_cutoff_percent' => 50.00,
        ]);
    }

    public function test_cutoff_percentages_must_be_between_0_and_100(): void
    {
        $this->postJson('/api/admin/contribution-deduction-settings', $this->payload([
            'first_cutoff_percent' => 150.00,
        ]))->assertStatus(422)->assertJsonValidationErrors('first_cutoff_percent');
    }

    public function test_authenticated_user_can_update_a_deduction_setting(): void
    {
        $setting = ContributionDeductionSetting::create($this->payload());

        $this->putJson("/api/admin/contribution-deduction-settings/{$setting->id}", $this->payload([
            'first_cutoff_percent' => 100.00,
            'second_cutoff_percent' => 0.00,
            'is_status' => false,
        ]))
            ->assertOk()
            ->assertJsonPath('data.first_cutoff_percent', '100.00')
            ->assertJsonPath('data.is_status', false);
    }

    public function test_authenticated_user_can_delete_a_deduction_setting(): void
    {
        $setting = ContributionDeductionSetting::create($this->payload());

        $this->deleteJson("/api/admin/contribution-deduction-settings/{$setting->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('contribution_deduction_settings', ['id' => $setting->id]);
    }
}
