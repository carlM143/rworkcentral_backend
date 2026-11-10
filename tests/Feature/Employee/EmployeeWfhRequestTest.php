<?php

namespace Tests\Feature\Employee;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeWfhRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_request_is_owned_visible_to_admin_and_reflects_decision(): void
    {
        $admin = User::factory()->create();
        $company = Company::create(['admin_id' => $admin->id, 'company_name' => 'Example']);
        $department = Department::create(['admin_id' => $admin->id, 'company_id' => $company->id, 'department_name' => 'Operations', 'department_code' => 'OPS', 'status' => true]);
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $employee = Employee::create(['user_id' => $user->id, 'department_id' => $department->id, 'emp_id' => 12345678, 'firstname' => 'First', 'lastname' => 'Employee', 'birthday' => '1990-01-01', 'status' => true]);
        $other = Employee::create(['user_id' => $otherUser->id, 'department_id' => $department->id, 'emp_id' => 87654321, 'firstname' => 'Other', 'lastname' => 'Employee', 'birthday' => '1990-01-01', 'status' => true]);
        Sanctum::actingAs($user);
        $payload = ['employee_id' => $other->id, 'start_date' => '2026-11-09', 'end_date' => '2026-11-10', 'supervisor_name' => 'Supervisor', 'reason' => 'Home work', 'request_date' => '2000-01-01'];
        $this->postJson('/api/employee/wfh-requests/preview', $payload)
            ->assertOk()
            ->assertJsonPath('data.number_of_days', 2)
            ->assertJsonStructure([
                'data' => [
                    'number_of_days',
                    'breakdown' => [
                        '*' => ['date', 'day_name', 'is_working_day', 'schedule_time'],
                    ],
                ],
            ]);
        $id = $this->postJson('/api/employee/wfh-requests', $payload)->assertCreated()->assertJsonPath('data.employee.id', $employee->id)->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->assertDatabaseHas('work_from_home', ['id' => $id, 'employee_id' => $employee->id]);
        $this->assertSame(now()->toDateString(), \App\Models\WorkFromHome::findOrFail($id)->request_date->toDateString());
        $this->postJson('/api/employee/wfh-requests', $payload)->assertUnprocessable()->assertJsonValidationErrors('start_date');
        Sanctum::actingAs($otherUser);
        $this->getJson('/api/employee/wfh-requests')->assertOk()->assertJsonCount(0, 'data');
        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/wfh-requests')->assertOk()->assertJsonFragment(['request_no' => 'WFH-2026-0001']);
        $this->patchJson("/api/admin/wfh-requests/{$id}/approve", [])->assertOk()->assertJsonPath('data.status', 'approved');
        Sanctum::actingAs($user);
        $this->getJson('/api/employee/wfh-requests')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'approved')
            ->assertJsonPath('data.0.counts.expected', 2);

        // Proof of Work cannot be attached directly before Time Out
        Storage::fake('public');
        $this->postJson("/api/employee/wfh-requests/{$id}/days", [
            'date_attendance' => '2026-11-09',
            'images' => [UploadedFile::fake()->image('proof.jpg', 400, 400)],
        ])->assertUnprocessable()->assertJsonValidationErrors('date_attendance');

        // Employee Time In, then Time Out with live Proof of Work for 2026-11-09
        $this->postJson('/api/employee/wfh-requests/time-in', ['date' => '2026-11-09'])
            ->assertOk()->assertJsonPath('data.has_approved_wfh', true);
        $this->post('/api/employee/wfh-requests/time-out', [
            'date' => '2026-11-09',
            'images' => [UploadedFile::fake()->image('proof.jpg', 400, 400)],
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.tracker.status', 'submitted');

        // After Time Out the employee may update the proof for that day
        $uploadResponse = $this->post("/api/employee/wfh-requests/{$id}/days", [
            'date_attendance' => '2026-11-09',
            'images' => [UploadedFile::fake()->image('proof2.jpg', 400, 400)],
        ], ['Accept' => 'application/json']);
        $uploadResponse->assertCreated()->assertJsonPath('data.days.0.status', 'submitted');
        $trackerId = $uploadResponse->json('data.days.0.tracker_id');
        $this->assertNotNull($trackerId);

        // Admin verifies the submitted proof of work -> records attendance in time_logs
        Sanctum::actingAs($admin);
        $this->patchJson("/api/admin/wfh-trackers/{$trackerId}/accept", [
            'remarks' => 'Verified output',
        ])->assertOk()->assertJsonPath('data.days.0.status', 'accepted');

        $timeLog = \App\Models\TimeLog::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', '2026-11-09')
            ->first();
        $this->assertNotNull($timeLog);
        $this->assertSame('wfh', $timeLog->source);
        $this->assertSame('complete', $timeLog->status);

        // Dashboard WFH Time In & Time Out with Live Proof of Work Images on 2026-11-10
        Sanctum::actingAs($user);
        $this->getJson('/api/employee/wfh-requests/today?date=2026-11-10')
            ->assertOk()
            ->assertJsonPath('data.has_approved_wfh', true)
            ->assertJsonPath('data.time_log', null);

        // Time Out before Time In should fail
        $this->post('/api/employee/wfh-requests/time-out', [
            'date' => '2026-11-10',
            'images' => [UploadedFile::fake()->image('live-proof.jpg', 400, 400)],
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        // Time In succeeds
        $this->postJson('/api/employee/wfh-requests/time-in', ['date' => '2026-11-10'])
            ->assertOk()
            ->assertJsonPath('data.has_approved_wfh', true)
            ->assertJsonPath('data.time_log.source', 'wfh');

        // Time Out with live proof of work image succeeds
        $timeOutResp = $this->post('/api/employee/wfh-requests/time-out', [
            'date' => '2026-11-10',
            'images' => [UploadedFile::fake()->image('live-proof.jpg', 400, 400)],
        ], ['Accept' => 'application/json']);
        $timeOutResp->assertOk()
            ->assertJsonPath('data.tracker.status', 'submitted');
        $this->assertNotNull($timeOutResp->json('data.time_log.time_out'));

        // Test schedule endpoint
        Sanctum::actingAs($user);
        $this->getJson('/api/employee/wfh-requests/schedule')->assertOk()->assertJsonStructure([
            'success',
            'data' => ['schedule_id', 'schedule_name', 'schedule_code', 'working_days', 'rest_days'],
        ]);

        // Admin can cancel an approved WFH request in Document Review without deleting records
        $cancelRecord = \App\Models\WorkFromHome::create([
            'employee_id' => $employee->id,
            'request_no' => 'WFH-2026-0002',
            'start_date' => '2026-11-12',
            'end_date' => '2026-11-13',
            'number_of_days' => 2,
            'reason' => 'Second WFH',
            'supervisor_name' => 'Supervisor',
            'request_date' => now()->toDateString(),
            'status' => 'approved',
        ]);

        $this->patchJson("/api/admin/wfh-documents/{$cancelRecord->id}/cancel", ['reason' => 'Schedule changed'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('work_from_home', [
            'id' => $cancelRecord->id,
            'status' => 'cancelled',
        ]);

        // Cancelled requests remain visible and deletion cannot remove proof or attendance.
        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/wfh-documents')->assertOk()->assertJsonFragment(['id' => $cancelRecord->id, 'document_status' => 'cancelled']);
        $this->patchJson("/api/admin/wfh-documents/{$id}/cancel", [])->assertOk()->assertJsonPath('data.document_status', 'cancelled');
        $submittedTracker = \App\Models\WfhTracker::where('work_from_home_id', $id)->where('status', 'submitted')->firstOrFail();
        $this->patchJson("/api/admin/wfh-trackers/{$submittedTracker->id}/accept", [])->assertUnprocessable();
        $this->patchJson("/api/admin/wfh-documents/{$id}/cancel", [])->assertUnprocessable();
        $this->deleteJson("/api/admin/wfh-requests/{$id}")
            ->assertStatus(405);

        $this->assertDatabaseHas('work_from_home', ['id' => $id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('wfh_tracker', ['work_from_home_id' => $id]);
        $this->assertDatabaseHas('time_logs', ['employee_id' => $employee->id, 'source' => 'wfh']);
    }

    public function test_authentication_and_employee_profile_are_required(): void
    {
        $this->getJson('/api/employee/wfh-requests')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/employee/wfh-requests')->assertNotFound();
        $this->postJson('/api/employee/wfh-requests', [])->assertNotFound();
    }
}
