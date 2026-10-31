<?php

namespace Tests\Feature\Admin;

use App\Models\ContributionRateBracket;
use App\Models\ContributionRateSchedule;
use App\Models\ContributionType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContributionRateScheduleTest extends TestCase
{
    use RefreshDatabase;

    private ContributionType $type;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create());

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
            'contribution_type_id' => $this->type->id,
            'calculation_method' => 'percentage',
            'salary_basis' => 'Monthly Salary Credit',
            'employee_rate' => 4.5,
            'employer_rate' => 9.5,
            'minimum_salary_base' => 5000,
            'maximum_salary_base' => 35000,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'is_status' => true,
        ], $overrides);
    }

    public function test_authenticated_user_can_list_rate_schedules(): void
    {
        ContributionRateSchedule::create($this->payload());

        $this->getJson('/api/admin/contribution-rate-schedules')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.calculation_method', 'percentage')
            ->assertJsonPath('data.0.contribution_type.contribution_code', 'SSS')
            ->assertJsonPath('data.0.is_status', true);
    }

    public function test_rate_schedule_index_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/admin/contribution-rate-schedules')->assertUnauthorized();
    }

    public function test_authenticated_user_can_create_a_rate_schedule(): void
    {
        $this->postJson('/api/admin/contribution-rate-schedules', $this->payload())
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.contribution_type_id', $this->type->id)
            ->assertJsonPath('data.salary_basis', 'Monthly Salary Credit');

        $this->assertDatabaseHas('contribution_rate_schedules', [
            'contribution_type_id' => $this->type->id,
            'calculation_method' => 'percentage',
            'effective_from' => '2026-01-01 00:00:00',
        ]);
    }

    public function test_calculation_method_must_be_a_valid_enum_value(): void
    {
        $this->postJson('/api/admin/contribution-rate-schedules', $this->payload([
            'calculation_method' => 'flat',
        ]))->assertStatus(422)->assertJsonValidationErrors('calculation_method');
    }

    public function test_effective_to_cannot_be_before_effective_from(): void
    {
        $this->postJson('/api/admin/contribution-rate-schedules', $this->payload([
            'effective_from' => '2026-06-01',
            'effective_to' => '2026-01-01',
        ]))->assertStatus(422)->assertJsonValidationErrors('effective_to');
    }

    public function test_contribution_type_must_exist(): void
    {
        $this->postJson('/api/admin/contribution-rate-schedules', $this->payload([
            'contribution_type_id' => 9999,
        ]))->assertStatus(422)->assertJsonValidationErrors('contribution_type_id');
    }

    public function test_authenticated_user_can_update_a_rate_schedule(): void
    {
        $schedule = ContributionRateSchedule::create($this->payload());

        $this->putJson("/api/admin/contribution-rate-schedules/{$schedule->id}", $this->payload([
            'calculation_method' => 'bracket',
            'employee_rate' => 5,
            'is_status' => false,
        ]))
            ->assertOk()
            ->assertJsonPath('data.calculation_method', 'bracket')
            ->assertJsonPath('data.is_status', false);
    }

    public function test_rate_schedule_can_be_deleted_when_unused(): void
    {
        $schedule = ContributionRateSchedule::create($this->payload());

        $this->deleteJson("/api/admin/contribution-rate-schedules/{$schedule->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('contribution_rate_schedules', ['id' => $schedule->id]);
    }

    public function test_rate_schedule_with_brackets_cannot_be_deleted(): void
    {
        $schedule = ContributionRateSchedule::create($this->payload());

        ContributionRateBracket::create([
            'contribution_rate_schedule_id' => $schedule->id,
            'salary_from' => 0,
            'salary_to' => 10000,
        ]);

        $this->deleteJson("/api/admin/contribution-rate-schedules/{$schedule->id}")
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('contribution_rate_schedules', ['id' => $schedule->id]);
    }
}
