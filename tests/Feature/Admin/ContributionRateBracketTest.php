<?php

namespace Tests\Feature\Admin;

use App\Models\ContributionRateBracket;
use App\Models\ContributionRateSchedule;
use App\Models\ContributionType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContributionRateBracketTest extends TestCase
{
    use RefreshDatabase;

    private ContributionRateSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create());

        $type = ContributionType::create([
            'contribution_code' => 'SSS',
            'contribution_name' => 'Social Security System',
            'provider' => 'SSS',
            'is_status' => true,
        ]);

        $this->schedule = ContributionRateSchedule::create([
            'contribution_type_id' => $type->id,
            'calculation_method' => 'bracket',
            'salary_basis' => 'Monthly Salary Credit',
            'effective_from' => '2026-01-01',
            'is_status' => true,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'contribution_rate_schedule_id' => $this->schedule->id,
            'salary_from' => 5000.00,
            'salary_to' => 9999.99,
            'monthly_salary_credit' => 7500.00,
            'employee_rate' => 4.5,
            'employer_rate' => 9.5,
            'employee_amount' => 337.50,
            'employer_amount' => 712.50,
            'employer_ec_amount' => 10.00,
        ], $overrides);
    }

    public function test_authenticated_user_can_list_rate_brackets(): void
    {
        ContributionRateBracket::create($this->payload());

        $this->getJson('/api/admin/contribution-rate-brackets')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.salary_from', '5000.00')
            ->assertJsonPath('data.0.rate_schedule.calculation_method', 'bracket');
    }

    public function test_rate_brackets_can_be_filtered_by_schedule(): void
    {
        ContributionRateBracket::create($this->payload());

        $this->getJson("/api/admin/contribution-rate-brackets?contribution_rate_schedule_id={$this->schedule->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/admin/contribution-rate-brackets?contribution_rate_schedule_id=99999')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_authenticated_user_can_create_a_bracket(): void
    {
        $this->postJson('/api/admin/contribution-rate-brackets', $this->payload())
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.salary_from', '5000.00');

        $this->assertDatabaseHas('contribution_rate_brackets', [
            'contribution_rate_schedule_id' => $this->schedule->id,
            'salary_from' => 5000.00,
            'salary_to' => 9999.99,
        ]);
    }

    public function test_salary_to_must_be_greater_than_or_equal_to_salary_from(): void
    {
        $this->postJson('/api/admin/contribution-rate-brackets', $this->payload([
            'salary_from' => 10000,
            'salary_to' => 5000,
        ]))->assertStatus(422)->assertJsonValidationErrors('salary_to');
    }

    public function test_schedule_must_exist(): void
    {
        $this->postJson('/api/admin/contribution-rate-brackets', $this->payload([
            'contribution_rate_schedule_id' => 99999,
        ]))->assertStatus(422)->assertJsonValidationErrors('contribution_rate_schedule_id');
    }

    public function test_authenticated_user_can_update_a_bracket(): void
    {
        $bracket = ContributionRateBracket::create($this->payload());

        $this->putJson("/api/admin/contribution-rate-brackets/{$bracket->id}", $this->payload([
            'monthly_salary_credit' => 8000.00,
        ]))
            ->assertOk()
            ->assertJsonPath('data.monthly_salary_credit', '8000.00');

        $this->assertDatabaseHas('contribution_rate_brackets', [
            'id' => $bracket->id,
            'monthly_salary_credit' => 8000.00,
        ]);
    }

    public function test_authenticated_user_can_delete_a_bracket(): void
    {
        $bracket = ContributionRateBracket::create($this->payload());

        $this->deleteJson("/api/admin/contribution-rate-brackets/{$bracket->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('contribution_rate_brackets', ['id' => $bracket->id]);
    }
}
