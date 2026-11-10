<?php

namespace Tests\Feature\Admin;

use App\Models\ContributionChangeLog;
use App\Models\ContributionRateSchedule;
use App\Models\ContributionType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContributionChangeLogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private ContributionType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'name' => 'HR Admin',
        ]);

        Sanctum::actingAs($this->admin);

        $this->type = ContributionType::create([
            'contribution_code' => 'SSS',
            'contribution_name' => 'Social Security System',
            'provider' => 'SSS',
            'is_status' => true,
        ]);
    }

    public function test_creating_updating_deactivating_versioning_and_deleting_rate_schedule_logs_changes(): void
    {
        // 1. Created (initial schedule for SSS)
        $res = $this->postJson('/api/admin/contribution-rate-schedules', [
            'contribution_type_id' => $this->type->id,
            'calculation_method' => 'percentage',
            'salary_basis' => 'Monthly Basic Salary',
            'employee_rate' => 4.5,
            'employer_rate' => 9.5,
            'minimum_salary_base' => 5000,
            'maximum_salary_base' => 35000,
            'effective_from' => '2025-01-01',
            'is_status' => true,
            'reason' => 'Initial 2025 SSS rate schedule.',
        ])->assertCreated();

        $scheduleId = $res->json('data.id');

        $this->assertDatabaseHas('contribution_change_logs', [
            'contribution_type_id' => $this->type->id,
            'contribution_rate_schedule_id' => $scheduleId,
            'entity_type' => 'rate_schedule',
            'entity_id' => $scheduleId,
            'action' => 'created',
            'changed_by' => $this->admin->id,
            'actor_name' => 'HR Admin',
            'reason' => 'Initial 2025 SSS rate schedule.',
        ]);

        // 2. Updated (rate change, same effective_from)
        $this->putJson("/api/admin/contribution-rate-schedules/{$scheduleId}", [
            'contribution_type_id' => $this->type->id,
            'calculation_method' => 'percentage',
            'salary_basis' => 'Monthly Basic Salary',
            'employee_rate' => 5.0,
            'employer_rate' => 10.0,
            'minimum_salary_base' => 5000,
            'maximum_salary_base' => 35000,
            'effective_from' => '2025-01-01',
            'is_status' => true,
            'reason' => 'Adjusted employee rate to 5%.',
        ])->assertOk();

        $this->assertDatabaseHas('contribution_change_logs', [
            'entity_type' => 'rate_schedule',
            'entity_id' => $scheduleId,
            'action' => 'updated',
            'reason' => 'Adjusted employee rate to 5%.',
        ]);

        // 3. Versioned (creating a second schedule for the same contribution type)
        $res2 = $this->postJson('/api/admin/contribution-rate-schedules', [
            'contribution_type_id' => $this->type->id,
            'calculation_method' => 'percentage',
            'salary_basis' => 'Monthly Basic Salary',
            'employee_rate' => 5.5,
            'employer_rate' => 10.5,
            'minimum_salary_base' => 5000,
            'maximum_salary_base' => 40000,
            'effective_from' => '2026-01-01',
            'is_status' => true,
        ])->assertCreated();

        $schedule2Id = $res2->json('data.id');

        $this->assertDatabaseHas('contribution_change_logs', [
            'entity_type' => 'rate_schedule',
            'entity_id' => $schedule2Id,
            'action' => 'versioned',
        ]);

        // 4. Deactivated (setting is_status from true to false)
        $this->putJson("/api/admin/contribution-rate-schedules/{$scheduleId}", [
            'contribution_type_id' => $this->type->id,
            'calculation_method' => 'percentage',
            'salary_basis' => 'Monthly Basic Salary',
            'employee_rate' => 5.0,
            'employer_rate' => 10.0,
            'minimum_salary_base' => 5000,
            'maximum_salary_base' => 35000,
            'effective_from' => '2025-01-01',
            'effective_to' => '2025-12-31',
            'is_status' => false,
        ])->assertOk();

        $this->assertDatabaseHas('contribution_change_logs', [
            'entity_type' => 'rate_schedule',
            'entity_id' => $scheduleId,
            'action' => 'deactivated',
        ]);

        // 5. Deleted
        $this->deleteJson("/api/admin/contribution-rate-schedules/{$schedule2Id}")
            ->assertOk();

        $this->assertDatabaseHas('contribution_change_logs', [
            'entity_type' => 'rate_schedule',
            'entity_id' => $schedule2Id,
            'action' => 'deleted',
            'contribution_rate_schedule_id' => null,
        ]);

        // 6. List via index endpoint with filters
        $this->getJson('/api/admin/contribution-change-logs?action=deactivated')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'deactivated');
    }
}
