<?php

namespace Tests\Feature\Admin;

use App\Models\ScheduleType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_a_work_schedule(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $scheduleType = ScheduleType::create([
            'type_name' => 'Fixed',
        ]);

        $response = $this->postJson('/api/admin/work-schedules', [
            'schedule_type_id' => $scheduleType->id,
            'schedule_code' => 'WS-001',
            'schedule_name' => 'Regular Day Shift',
            'time_in' => '08:00',
            'time_out' => '17:00',
            'required_work_hours' => 8,
            'grace_period' => 15,
            'break_start' => '12:00',
            'break_end' => '13:00',
            'status' => true,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.schedule_code', 'WS-001')
            ->assertJsonPath('data.grace_period', 15)
            ->assertJsonPath('data.status', true);

        $this->assertDatabaseHas('work_schedules', [
            'schedule_type_id' => $scheduleType->id,
            'schedule_code' => 'WS-001',
            'schedule_name' => 'Regular Day Shift',
            'required_work_hours' => 8,
            'grace_period' => 15,
            'status' => true,
        ]);
    }
}
