<?php

namespace Tests\Feature\Admin;

use App\Models\ScheduleType;
use App\Models\User;
use App\Models\WorkScheduleDay;
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
            'required_work_hours' => 8,
            'grace_period' => 15,
            'status' => true,
            'days' => [
                ['day_of_week' => 'Monday', 'time_in' => '08:00', 'time_out' => '17:00'],
                ['day_of_week' => 'Tuesday', 'time_in' => '08:00', 'time_out' => '17:00'],
                ['day_of_week' => 'Wednesday', 'time_in' => '08:00', 'time_out' => '17:00'],
                ['day_of_week' => 'Thursday', 'is_working_day' => false],
                ['day_of_week' => 'Friday', 'time_in' => '08:00', 'time_out' => '17:00'],
                ['day_of_week' => 'Sat', 'time_in' => '08:00', 'time_out' => '12:00'],
                ['day_of_week' => 'Sun', 'is_working_day' => false],
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.schedule_code', 'WS-001')
            ->assertJsonPath('data.grace_period', 15)
            ->assertJsonPath('data.status', true)
            ->assertJsonCount(5, 'data.days');

        $this->assertDatabaseHas('work_schedules', [
            'schedule_type_id' => $scheduleType->id,
            'schedule_code' => 'WS-001',
            'schedule_name' => 'Regular Day Shift',
            'required_work_hours' => 8,
            'grace_period' => 15,
            'status' => true,
        ]);
        $this->assertDatabaseMissing('work_schedule_days', [
            'work_schedule_id' => $response->json('data.id'),
            'day_of_week' => 'Thursday',
        ]);
        $this->assertDatabaseMissing('work_schedule_days', [
            'work_schedule_id' => $response->json('data.id'),
            'day_of_week' => 'Sun',
        ]);

        WorkScheduleDay::create([
            'work_schedule_id' => $response->json('data.id'),
            'day_of_week' => 'Sun',
            'is_working_day' => false,
        ]);

        $update = $this->patchJson('/api/admin/work-schedules/'.$response->json('data.id'), [
            'schedule_type_id' => $scheduleType->id,
            'schedule_code' => 'WS-001',
            'schedule_name' => 'Updated Schedule',
            'required_work_hours' => 8,
            'grace_period' => 15,
            'status' => true,
            'days' => [
                ['day_of_week' => 'Monday', 'time_in' => '08:00', 'time_out' => '17:00'],
                ['day_of_week' => 'Sun', 'time_in' => '08:00', 'time_out' => '12:00'],
            ],
        ]);

        $update->assertOk()->assertJsonCount(2, 'data.days');
        $this->assertDatabaseHas('work_schedule_days', [
            'work_schedule_id' => $response->json('data.id'),
            'day_of_week' => 'Sun',
            'is_working_day' => true,
        ]);
        $this->assertDatabaseMissing('work_schedule_days', [
            'work_schedule_id' => $response->json('data.id'),
            'day_of_week' => 'Tuesday',
        ]);
    }
}
