<?php

namespace Tests\Feature\Admin;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeOffboarding;
use App\Models\LifecycleEvaluationDocument;
use App\Models\Role;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

class LifecycleWorkflowTest extends LifecycleConfigurationTest
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            '2026_10_21_025203_create_positions_table.php',
            '2026_10_21_025212_create_work_schedules_table.php',
            '2026_10_21_025213_create_locations_table.php',
            '2026_10_21_025214_create_employments_table.php',
            '2026_11_12_120000_create_lifecycle_evaluation_documents_table.php',
            '2026_11_12_130000_complete_lifecycle_workflows.php',
            '2026_11_12_140000_add_stage_attachment_requirement.php',
            '2026_11_12_150000_create_lifecycle_employment_type_changes.php',
        ] as $migration) (require database_path('migrations/'.$migration))->up();
    }

    private function employee(): Employee
    {
        $admin = User::factory()->create(['role_id' => Role::firstOrCreate(['name' => 'Administrator'])->id]);
        Sanctum::actingAs($admin);
        $company = Company::create(['admin_id' => $admin->id, 'company_name' => 'Example']);
        $department = Department::create(['admin_id' => $admin->id, 'company_id' => $company->id, 'department_name' => 'Operations', 'department_code' => 'OPS', 'status' => true]);
        return Employee::create(['user_id' => $admin->id, 'department_id' => $department->id, 'emp_id' => 12345678, 'firstname' => 'First', 'lastname' => 'Employee', 'birthday' => '1990-01-01', 'status' => true]);
    }

    public function test_loading_milestones_detects_employees_and_preserves_existing_evaluations(): void
    {
        $employee = $this->employee();
        $type = \App\Models\EmploymentType::create(['name' => 'Contractual']);
        $position = \App\Models\Position::create(['department_id' => $employee->department_id, 'position' => 'Coordinator', 'description' => 'Coordinates operations', 'status' => true]);
        \App\Models\Employment::create(['employee_id' => $employee->id, 'employment_type_id' => $type->id, 'department_id' => $employee->department_id, 'position_id' => $position->id, 'date_hired' => '2026-01-31']);
        $policy = $this->postJson('/api/admin/lifecycle/policies', ['name' => 'Contract review', 'employment_type_id' => $type->id, 'status' => true])->json('data.id');
        $stageData = ['lifecycle_policy_id' => $policy, 'stage_name' => 'Review', 'stage_code' => 'REVIEW', 'months_after_hire' => 1, 'sort_order' => 1, 'status' => true];
        $stage = $this->postJson('/api/admin/lifecycle/stages', $stageData)->json('data.id');
        $this->postJson('/api/admin/lifecycle/stages', [...$stageData, 'stage_code' => 'DISABLED', 'status' => false]);
        $this->getJson('/api/admin/lifecycle/milestones')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.dueDate', '2026-02-28');
        $record = \App\Models\EmployeeLifecycleMilestone::where('lifecycle_stage_id', $stage)->firstOrFail();
        $record->update(['status' => 'completed', 'remarks' => 'Already reviewed']);
        $this->getJson('/api/admin/lifecycle/milestones')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.remarks', 'Already reviewed')->assertJsonPath('data.0.status', 'completed');
        $other = $employee->replicate(); $other->emp_id = 87654321; $other->user_id = User::factory()->create()->id; $other->save();
        \App\Models\Employment::create(['employee_id' => $other->id, 'employment_type_id' => $type->id, 'department_id' => $employee->department_id, 'position_id' => $position->id, 'date_hired' => '2026-03-01']);
        $this->getJson('/api/admin/lifecycle/milestones')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_offboarding_persists_clearance_and_enforces_completion_and_access(): void
    {
        $employee = $this->employee();
        $data = ['employeeId' => $employee->id, 'offboardingType' => 'Resignation', 'noticeDate' => '2026-11-01', 'lastWorkingDay' => '2026-11-30', 'separationDate' => '2026-12-01', 'reason' => 'Relocation', 'status' => 'Pending Clearance', 'finalPayStatus' => 'Not Started',
            'checklist' => array_fill_keys(['hrClearance', 'departmentClearance', 'financeClearance', 'itClearance', 'assetReturn', 'exitInterview'], false)];
        $id = $this->postJson('/api/admin/lifecycle/offboarding', $data)->assertOk()->assertJsonPath('data.clearanceProgress', 0)->json('data.id');
        $this->getJson('/api/admin/lifecycle/offboarding')->assertOk()->assertJsonPath('data.0.employeeName', 'First Employee');
        $this->postJson('/api/admin/lifecycle/offboarding', $data)->assertUnprocessable();
        $this->putJson("/api/admin/lifecycle/offboarding/{$id}", [...$data, 'status' => 'Completed'])->assertUnprocessable();
        $data['checklist'] = array_fill_keys(array_keys($data['checklist']), true);
        $this->putJson("/api/admin/lifecycle/offboarding/{$id}", [...$data, 'status' => 'Completed'])->assertUnprocessable();
        $this->putJson("/api/admin/lifecycle/offboarding/{$id}", [...$data, 'status' => 'Completed', 'finalPayStatus' => 'Released'])->assertOk()->assertJsonPath('data.clearanceProgress', 100);
        $this->assertSame('Completed', EmployeeOffboarding::find($id)->status);
        $this->putJson("/api/admin/lifecycle/offboarding/{$id}", $data)->assertUnprocessable();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/admin/lifecycle/offboarding')->assertForbidden();
        $this->postJson('/api/admin/lifecycle/offboarding', $data)->assertForbidden();
        $this->getJson('/api/admin/lifecycle/milestones')->assertForbidden();
    }

    public function test_employment_type_change_updates_record_and_audits_once(): void
    {
        $employee = $this->employee();
        $old = \App\Models\EmploymentType::create(['name' => 'Probationary']);
        $new = \App\Models\EmploymentType::create(['name' => 'Regular']);
        $position = \App\Models\Position::create(['department_id' => $employee->department_id, 'position' => 'Coordinator', 'description' => 'Coordinates operations', 'status' => true]);
        $employment = \App\Models\Employment::create(['employee_id' => $employee->id, 'employment_type_id' => $old->id, 'department_id' => $employee->department_id, 'position_id' => $position->id, 'date_hired' => '2026-01-31']);
        $url = "/api/admin/lifecycle/employees/{$employee->id}/employment-type-changes";
        $payload = ['employment_id' => $employment->id, 'previous_type_id' => $old->id, 'employment_type_id' => $new->id, 'reason' => 'Qualified after evaluation'];
        $this->postJson($url, [...$payload, 'reason' => ''])->assertUnprocessable();
        $this->postJson($url, [...$payload, 'employment_type_id' => $old->id])->assertUnprocessable();
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.employmentType', 'Regular');
        $this->assertEquals($new->id, $employment->fresh()->employment_type_id);
        $this->assertSame('2026-01-31', $employment->fresh()->date_hired->format('Y-m-d'));
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.previous_type_name', 'Probationary')->assertJsonPath('data.0.new_type_name', 'Regular')->assertJsonPath('data.0.changed_by', $employee->user_id);
        $this->postJson($url, $payload)->assertUnprocessable();
        $this->getJson($url)->assertJsonCount(1, 'data');
        $historyUrl = "/api/admin/lifecycle/employees/{$employee->id}/history";
        $this->getJson($historyUrl)->assertOk()
            ->assertJsonPath('data.employee.employmentType', 'Regular')
            ->assertJsonCount(2, 'data.events')
            ->assertJsonPath('data.events.0.title', 'Employment record — Probationary')
            ->assertJsonPath('data.events.1.title', 'Probationary → Regular')
            ->assertJsonPath('data.events.1.description', 'Qualified after evaluation');
        $other = $employee->replicate(); $other->emp_id = 87654321; $other->user_id = User::factory()->create()->id; $other->save();
        $this->getJson("/api/admin/lifecycle/employees/{$other->id}/history")->assertOk()->assertJsonCount(0, 'data.events');
        $this->getJson('/api/admin/lifecycle/employees/999999/history')->assertNotFound();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson($historyUrl)->assertForbidden();
        $this->postJson($url, $payload)->assertForbidden();
        $this->getJson($url)->assertForbidden();
        $this->assertEquals($new->id, $employment->fresh()->employment_type_id);
    }

    public function test_employment_type_change_rebuilds_milestones_from_hire_date_and_policy_stages(): void
    {
        $employee = $this->employee();
        $probationary = \App\Models\EmploymentType::create(['name' => 'Probationary']);
        $regular = \App\Models\EmploymentType::create(['name' => 'Regular']);
        $position = \App\Models\Position::create(['department_id' => $employee->department_id, 'position' => 'Coordinator', 'description' => 'Coordinates operations', 'status' => true]);
        $employment = \App\Models\Employment::create(['employee_id' => $employee->id, 'employment_type_id' => $probationary->id, 'department_id' => $employee->department_id, 'position_id' => $position->id, 'date_hired' => '2026-01-31']);

        $probationPolicy = $this->postJson('/api/admin/lifecycle/policies', ['name' => 'Probation', 'employment_type_id' => $probationary->id, 'status' => true])->json('data.id');
        $probationStage = $this->postJson('/api/admin/lifecycle/stages', ['lifecycle_policy_id' => $probationPolicy, 'stage_name' => '3rd Month Evaluation', 'stage_code' => 'M3_EVAL', 'months_after_hire' => 3, 'sort_order' => 1, 'status' => true])->json('data.id');
        $pendingProbationStage = $this->postJson('/api/admin/lifecycle/stages', ['lifecycle_policy_id' => $probationPolicy, 'stage_name' => '5th Month Review', 'stage_code' => 'M5_REVIEW', 'months_after_hire' => 5, 'sort_order' => 2, 'status' => true])->json('data.id');
        $regularPolicy = $this->postJson('/api/admin/lifecycle/policies', ['name' => 'Regular milestones', 'employment_type_id' => $regular->id, 'status' => true])->json('data.id');
        $regularStage = $this->postJson('/api/admin/lifecycle/stages', ['lifecycle_policy_id' => $regularPolicy, 'stage_name' => '1st Year Anniversary', 'stage_code' => 'Y1_ANNIVERSARY', 'months_after_hire' => 12, 'sort_order' => 1, 'status' => true])->json('data.id');

        $this->getJson('/api/admin/lifecycle/milestones')->assertOk()->assertJsonCount(2, 'data');
        $completed = \App\Models\EmployeeLifecycleMilestone::where('lifecycle_stage_id', $probationStage)->firstOrFail();
        $completed->update(['status' => 'completed', 'remarks' => 'Passed evaluation']);

        $this->postJson("/api/admin/lifecycle/employees/{$employee->id}/employment-type-changes", [
            'employment_id' => $employment->id,
            'previous_type_id' => $probationary->id,
            'employment_type_id' => $regular->id,
            'reason' => 'Regularized after evaluation',
        ])->assertOk();

        // Completed probation history is preserved, pending probation work is replaced.
        $this->assertDatabaseHas('employee_lifecycle_milestones', ['id' => $completed->id, 'status' => 'completed']);
        $this->assertDatabaseMissing('employee_lifecycle_milestones', ['lifecycle_stage_id' => $pendingProbationStage]);

        $newMilestone = \App\Models\EmployeeLifecycleMilestone::where('lifecycle_stage_id', $regularStage)->firstOrFail();
        $this->assertSame('2027-01-31', $newMilestone->due_date->format('Y-m-d'));
        $this->assertSame('pending', $newMilestone->status);
        $this->getJson('/api/admin/lifecycle/milestones')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_milestone_document_must_belong_to_employee_and_can_be_detached(): void
    {
        $employee = $this->employee();
        $policy = $this->postJson('/api/admin/lifecycle/policies', ['name' => 'Probation', 'status' => true])->json('data.id');
        $stage = $this->postJson('/api/admin/lifecycle/stages', ['lifecycle_policy_id' => $policy, 'stage_name' => 'Review', 'stage_code' => 'REVIEW', 'months_after_hire' => 3, 'sort_order' => 1, 'status' => true])->json('data.id');
        $data = ['employee_id' => $employee->id, 'lifecycle_stage_id' => $stage, 'due_date' => '2026-12-01'];
        $id = $this->postJson('/api/admin/lifecycle/milestones', $data)->assertCreated()->json('data.id');
        $this->putJson("/api/admin/lifecycle/milestones/{$id}", ['evaluation_document_id' => null, 'status' => 'completed'])->assertUnprocessable();
        $this->postJson('/api/admin/lifecycle/milestones', [...$data, 'due_date' => '2026-12-02'])->assertUnprocessable();
        $document = LifecycleEvaluationDocument::create(['employee_id' => $employee->id, 'uploaded_by' => $employee->user_id, 'path' => 'evaluation.pdf', 'original_name' => 'evaluation.pdf', 'mime_type' => 'application/pdf', 'size' => 100]);
        $this->putJson("/api/admin/lifecycle/milestones/{$id}", ['evaluation_document_id' => $document->id, 'remarks' => 'Reviewed'])->assertOk()->assertJsonPath('data.status', 'completed');
        $this->getJson('/api/admin/lifecycle/milestones')->assertOk()->assertJsonPath('data.0.attachmentName', 'evaluation.pdf');
        $this->putJson("/api/admin/lifecycle/milestones/{$id}", ['evaluation_document_id' => null])->assertOk()->assertJsonPath('data.status', 'pending');
        $this->putJson("/api/admin/lifecycle/stages/{$stage}", ['lifecycle_policy_id' => $policy, 'stage_name' => 'Review', 'stage_code' => 'REVIEW', 'months_after_hire' => 3, 'sort_order' => 1, 'status' => true, 'requires_attachment' => false])->assertOk()->assertJsonPath('data.requires_attachment', false);
        $this->putJson("/api/admin/lifecycle/milestones/{$id}", ['evaluation_document_id' => null, 'status' => 'completed'])->assertOk()->assertJsonPath('data.status', 'completed');
        $this->getJson('/api/admin/lifecycle/milestones')->assertOk()->assertJsonPath('data.0.requiresAttachment', false);
        $this->putJson("/api/admin/lifecycle/milestones/{$id}", ['evaluation_document_id' => null, 'status' => 'pending'])->assertOk()->assertJsonPath('data.status', 'pending');
        $other = $employee->replicate(); $other->emp_id = 87654321; $other->user_id = User::factory()->create()->id; $other->save();
        $document->update(['employee_id' => $other->id]);
        $this->putJson("/api/admin/lifecycle/milestones/{$id}", ['evaluation_document_id' => $document->id])->assertUnprocessable();
    }
}
