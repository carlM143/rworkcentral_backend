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
            '2026_11_18_000001_add_effective_date_to_lifecycle_employment_type_changes.php',
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

    public function test_lifecycle_seeder_reuses_existing_probi_to_regular_policy_and_stage(): void
    {
        $employee = $this->employee();
        $probationary = \App\Models\EmploymentType::create(['name' => 'Probationary']);
        $position = \App\Models\Position::create(['department_id' => $employee->department_id, 'position' => 'Coordinator', 'description' => 'Coordinates operations', 'status' => true]);
        \App\Models\Employment::create(['employee_id' => $employee->id, 'employment_type_id' => $probationary->id, 'department_id' => $employee->department_id, 'position_id' => $position->id, 'date_hired' => '2025-01-31']);
        $policy = \App\Models\EmployeeLifecyclePolicy::create([
            'user_id' => $employee->user_id, 'employment_type_id' => $probationary->id,
            'name' => 'probi to regular', 'description' => 'HR configured policy', 'status' => true,
        ]);
        $stage = $policy->stages()->create([
            'stage_name' => 'probi to regular', 'stage_code' => 'STG_PROBI_TO_REGULAR',
            'months_after_hire' => 3, 'end_month_after_hire' => 4, 'sort_order' => 2,
            'requires_attachment' => true, 'status' => true,
        ]);
        $milestone = $employee->lifecycleMilestones()->create([
            'lifecycle_stage_id' => $stage->id, 'due_date' => '2025-05-31',
            'status' => 'completed', 'remarks' => 'Existing evaluation',
        ]);

        $this->seed(\Database\Seeders\EmployeeLifecycleSeeder::class);
        $this->seed(\Database\Seeders\EmployeeLifecycleSeeder::class);

        $this->assertDatabaseCount('employee_lifecycle_policies', 1);
        $this->assertDatabaseCount('employee_lifecycle_stages', 1);
        $this->assertDatabaseCount('employee_lifecycle_milestones', 1);
        $this->assertDatabaseHas('employee_lifecycle_policies', [
            'id' => $policy->id, 'name' => 'probi to regular', 'description' => 'HR configured policy',
            'employment_type_id' => $probationary->id,
        ]);
        $this->assertDatabaseHas('employee_lifecycle_stages', [
            'id' => $stage->id, 'stage_code' => 'STG_PROBI_TO_REGULAR',
            'months_after_hire' => 3, 'end_month_after_hire' => 4, 'sort_order' => 2,
            'requires_attachment' => true, 'is_managed_regularization' => true,
        ]);
        $this->assertDatabaseHas('employee_lifecycle_milestones', [
            'id' => $milestone->id, 'status' => 'completed', 'remarks' => 'Existing evaluation',
        ]);
        $this->getJson('/api/admin/lifecycle/policies')->assertOk()
            ->assertJsonPath('data.0.regularization_stage.id', $stage->id)
            ->assertJsonPath('data.0.regularization_stage.end_month_after_hire', 4);
    }

    public function test_employee_directory_can_order_recorded_lifecycle_activity_latest_first(): void
    {
        $first = $this->employee();
        $first->update(['firstname' => 'Aaron', 'lastname' => 'Alpha']);
        $second = $first->replicate();
        $second->emp_id = 87654321;
        $second->firstname = 'Zoe';
        $second->lastname = 'Zulu';
        $second->user_id = User::factory()->create()->id;
        $second->save();
        $third = $first->replicate();
        $third->emp_id = 11112222;
        $third->firstname = 'Beth';
        $third->lastname = 'Beta';
        $third->user_id = User::factory()->create()->id;
        $third->save();
        $fourth = $first->replicate();
        $fourth->emp_id = 11113333;
        $fourth->firstname = 'Cara';
        $fourth->lastname = 'Gamma';
        $fourth->user_id = User::factory()->create()->id;
        $fourth->save();

        $old = \App\Models\EmploymentType::create(['name' => 'Probationary']);
        $new = \App\Models\EmploymentType::create(['name' => 'Regular']);
        $position = \App\Models\Position::create(['department_id' => $first->department_id, 'position' => 'Coordinator', 'description' => 'Coordinates operations', 'status' => true]);
        $employment = \App\Models\Employment::create(['employee_id' => $first->id, 'employment_type_id' => $new->id, 'department_id' => $first->department_id, 'position_id' => $position->id, 'date_hired' => '2025-01-01']);
        \Illuminate\Support\Facades\DB::table('lifecycle_employment_type_changes')->insert([
            'employee_id' => $first->id, 'employment_id' => $employment->id, 'changed_by' => $first->user_id,
            'previous_type_id' => $old->id, 'new_type_id' => $new->id, 'previous_type_name' => 'Probationary',
            'new_type_name' => 'Regular', 'reason' => '', 'effective_date' => '2030-01-01',
            'created_at' => '2026-01-01 12:00:00', 'updated_at' => '2026-01-01 12:00:00',
        ]);
        $policyId = $this->postJson('/api/admin/lifecycle/policies', ['name' => 'Test policy', 'status' => true])->json('data.id');
        $stageId = $this->postJson('/api/admin/lifecycle/stages', ['lifecycle_policy_id' => $policyId, 'stage_name' => 'Review', 'months_after_hire' => 3, 'sort_order' => 1, 'status' => true])->json('data.id');
        $milestone = \App\Models\EmployeeLifecycleMilestone::create(['employee_id' => $second->id, 'lifecycle_stage_id' => $stageId, 'due_date' => '2026-01-01', 'status' => 'completed']);
        \Illuminate\Support\Facades\DB::table('employee_lifecycle_milestones')->where('id', $milestone->id)->update(['updated_at' => '2026-02-01 12:00:00']);
        $pending = \App\Models\EmployeeLifecycleMilestone::create(['employee_id' => $third->id, 'lifecycle_stage_id' => $stageId, 'due_date' => '2026-01-01', 'status' => 'pending']);
        \Illuminate\Support\Facades\DB::table('employee_lifecycle_milestones')->where('id', $pending->id)->update(['updated_at' => '2026-04-01 12:00:00']);
        $offboarding = EmployeeOffboarding::create(['employee_id' => $fourth->id, 'user_id' => $first->user_id, 'status' => 'Pending Clearance', 'details' => []]);
        \Illuminate\Support\Facades\DB::table('employee_offboardings')->where('id', $offboarding->id)->update(['updated_at' => '2026-03-01 12:00:00']);

        $this->getJson('/api/admin/lifecycle/employees?lifecycle_activity=1')->assertOk()
            ->assertJsonPath('data.0.employeeId', $fourth->id)
            ->assertJsonPath('data.1.employeeId', $second->id)
            ->assertJsonPath('data.2.employeeId', $first->id)
            ->assertJsonPath('data.3.employeeId', $third->id)
            ->assertJsonPath('data.0.lastLifecycleActivityAt', '2026-03-01 12:00:00')
            ->assertJsonPath('data.3.lastLifecycleActivityAt', null);
        $this->getJson('/api/admin/lifecycle/employees')->assertOk()->assertJsonPath('data.0.employeeId', $first->id);
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/admin/lifecycle/employees?lifecycle_activity=1')->assertForbidden();
    }

    public function test_lifecycle_seeder_only_creates_the_probationary_regularization_plan(): void
    {
        $employee = $this->employee();
        $probationary = \App\Models\EmploymentType::create(['name' => 'Probationary']);
        $regular = \App\Models\EmploymentType::create(['name' => 'Regular']);
        $position = \App\Models\Position::create(['department_id' => $employee->department_id, 'position' => 'Coordinator', 'description' => 'Coordinates operations', 'status' => true]);
        $employment = \App\Models\Employment::create(['employee_id' => $employee->id, 'employment_type_id' => $probationary->id, 'department_id' => $employee->department_id, 'position_id' => $position->id, 'date_hired' => '2025-01-31']);
        $other = $employee->replicate();
        $other->emp_id = 87654321;
        $other->user_id = User::factory()->create()->id;
        $other->save();
        \App\Models\Employment::create(['employee_id' => $other->id, 'employment_type_id' => $regular->id, 'department_id' => $other->department_id, 'position_id' => $position->id, 'date_hired' => '2025-01-31']);

        $this->seed(\Database\Seeders\EmployeeLifecycleSeeder::class);
        $this->assertDatabaseCount('employee_lifecycle_policies', 1);
        $this->assertDatabaseCount('employee_lifecycle_stages', 1);
        $stage = \App\Models\EmployeeLifecycleStage::where('stage_code', 'M6_REGULARIZATION')->firstOrFail();
        $legacyStage = $stage->lifecyclePolicy->stages()->create([
            'stage_code' => 'M3_EVAL', 'stage_name' => '3rd Month Review', 'months_after_hire' => 3,
            'sort_order' => 1, 'status' => true,
        ]);
        $legacyMilestone = $employee->lifecycleMilestones()->create([
            'lifecycle_stage_id' => $legacyStage->id, 'due_date' => '2025-04-30', 'status' => 'completed',
        ]);
        $milestone = $employee->lifecycleMilestones()->where('lifecycle_stage_id', $stage->id)->firstOrFail();
        $this->assertSame('2025-07-31', $milestone->due_date->format('Y-m-d'));
        $this->assertSame('pending', $milestone->status);
        $milestone->update(['remarks' => 'HR review in progress']);

        $this->seed(\Database\Seeders\EmployeeLifecycleSeeder::class);
        $this->assertDatabaseCount('employee_lifecycle_milestones', 2);
        $this->assertFalse($legacyStage->fresh()->status);
        $this->assertSame('completed', $legacyMilestone->fresh()->status);
        $this->getJson('/api/admin/lifecycle/milestones')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('stats.probationaryUpcoming', 1)
            ->assertJsonPath('data.0.stageName', $stage->stage_name)
            ->assertJsonPath('data.0.reviewWindowStart', '2025-04-30')
            ->assertJsonPath('data.0.reviewWindowEnd', '2025-07-31');
        $this->getJson("/api/admin/lifecycle/employees/{$employee->id}/history")->assertOk()
            ->assertJsonCount(1, 'data.pendingMilestones')
            ->assertJsonPath('data.pendingMilestones.0.id', $milestone->id)
            ->assertJsonPath('data.pendingMilestones.0.reviewWindowStart', '2025-04-30')
            ->assertJsonPath('data.pendingMilestones.0.reviewWindowEnd', '2025-07-31')
            ->assertJsonPath('data.events.1.title', '3rd Month Review');
        $this->getJson("/api/admin/lifecycle/employees/{$other->id}/history")->assertOk()
            ->assertJsonCount(0, 'data.pendingMilestones');
        $this->assertSame('HR review in progress', $milestone->fresh()->remarks);
        $this->assertEquals($probationary->id, $employment->fresh()->employment_type_id);
        $this->assertDatabaseCount('lifecycle_employment_type_changes', 0);

        $policy = $stage->lifecyclePolicy;
        $url = "/api/admin/lifecycle/stages/{$stage->id}";
        $payload = [
            'lifecycle_policy_id' => $policy->id, 'stage_name' => $stage->stage_name,
            'status' => true, 'sort_order' => 1, 'months_after_hire' => 2, 'end_month_after_hire' => 5,
        ];
        $this->putJson($url, [...$payload, 'end_month_after_hire' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('end_month_after_hire');
        $this->putJson($url, $payload)->assertOk()
            ->assertJsonPath('data.months_after_hire', 2)
            ->assertJsonPath('data.end_month_after_hire', 5)
            ->assertJsonPath('data.stage_code', 'M6_REGULARIZATION');
        $this->getJson('/api/admin/lifecycle/milestones')->assertOk()
            ->assertJsonPath('data.0.id', $milestone->id)
            ->assertJsonPath('data.0.reviewWindowStart', '2025-03-31')
            ->assertJsonPath('data.0.reviewWindowEnd', '2025-06-30')
            ->assertJsonPath('data.0.dueDate', '2025-06-30')
            ->assertJsonPath('data.0.remarks', 'HR review in progress');
        $this->assertSame('2025-04-30', $legacyMilestone->fresh()->due_date->toDateString());
        $this->seed(\Database\Seeders\EmployeeLifecycleSeeder::class);
        $this->assertDatabaseCount('employee_lifecycle_policies', 1);
        $this->assertSame(2, $stage->fresh()->months_after_hire);
        $this->assertSame(5, $stage->fresh()->end_month_after_hire);
    }

    public function test_loading_milestones_detects_employees_and_preserves_existing_evaluations(): void
    {
        $employee = $this->employee();
        $type = \App\Models\EmploymentType::create(['name' => 'Contractual']);
        $position = \App\Models\Position::create(['department_id' => $employee->department_id, 'position' => 'Coordinator', 'description' => 'Coordinates operations', 'status' => true]);
        \App\Models\Employment::create(['employee_id' => $employee->id, 'employment_type_id' => $type->id, 'department_id' => $employee->department_id, 'position_id' => $position->id, 'date_hired' => '2026-01-31']);
        $policy = $this->postJson('/api/admin/lifecycle/policies', ['name' => 'Contract review', 'employment_type_id' => $type->id, 'status' => true])->json('data.id');
        $stageData = ['lifecycle_policy_id' => $policy, 'stage_name' => 'Review', 'months_after_hire' => 1, 'sort_order' => 1, 'status' => true];
        $stage = $this->postJson('/api/admin/lifecycle/stages', $stageData)->json('data.id');
        $this->postJson('/api/admin/lifecycle/stages', [...$stageData, 'stage_name' => 'Disabled review', 'status' => false]);
        $this->getJson('/api/admin/lifecycle/milestones')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.dueDate', '2026-02-28');
        $record = \App\Models\EmployeeLifecycleMilestone::where('lifecycle_stage_id', $stage)->firstOrFail();
        $this->putJson("/api/admin/lifecycle/stages/{$stage}", [...$stageData, 'end_month_after_hire' => 3])->assertOk();
        $this->getJson('/api/admin/lifecycle/milestones')->assertOk()
            ->assertJsonPath('data.0.reviewWindowStart', '2026-02-28')
            ->assertJsonPath('data.0.reviewWindowEnd', '2026-04-30')
            ->assertJsonPath('data.0.dueDate', '2026-04-30');
        $this->putJson("/api/admin/lifecycle/stages/{$stage}", [...$stageData, 'end_month_after_hire' => null])->assertOk();
        $this->assertSame('2026-02-28', $record->fresh()->due_date->toDateString());
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
        $payload = ['employment_id' => $employment->id, 'previous_type_id' => $old->id, 'employment_type_id' => $new->id, 'reason' => 'Qualified after evaluation', 'effective_date' => '2026-08-01'];
        $this->postJson($url, [...$payload, 'effective_date' => null])->assertUnprocessable()->assertJsonValidationErrors('effective_date');
        $this->postJson($url, [...$payload, 'effective_date' => '2026-02-30'])->assertUnprocessable()->assertJsonValidationErrors('effective_date');
        $this->postJson($url, [...$payload, 'employment_type_id' => $old->id])->assertUnprocessable();
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.employmentType', 'Regular');
        $this->assertEquals($new->id, $employment->fresh()->employment_type_id);
        $this->assertSame('2026-01-31', $employment->fresh()->date_hired->format('Y-m-d'));
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.previous_type_name', 'Probationary')->assertJsonPath('data.0.new_type_name', 'Regular')->assertJsonPath('data.0.changed_by', $employee->user_id)->assertJsonPath('data.0.effective_date', '2026-08-01');
        $this->postJson($url, $payload)->assertUnprocessable();
        $this->getJson($url)->assertJsonCount(1, 'data');
        $historyUrl = "/api/admin/lifecycle/employees/{$employee->id}/history";
        $this->getJson($historyUrl)->assertOk()
            ->assertJsonPath('data.employee.employmentType', 'Regular')
            ->assertJsonCount(2, 'data.events')
            ->assertJsonPath('data.events.0.title', 'Employment record — Probationary')
            ->assertJsonPath('data.events.1.title', 'Probationary → Regular')
            ->assertJsonPath('data.events.1.date', '2026-08-01')
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

        $this->seed(\Database\Seeders\EmployeeLifecycleSeeder::class);
        $probationStage = \App\Models\EmployeeLifecycleStage::where('stage_code', 'M6_REGULARIZATION')->firstOrFail();
        $regularPolicy = $this->postJson('/api/admin/lifecycle/policies', ['name' => 'Regular milestones', 'employment_type_id' => $regular->id, 'status' => true])->json('data.id');
        $regularStage = $this->postJson('/api/admin/lifecycle/stages', ['lifecycle_policy_id' => $regularPolicy, 'stage_name' => '1st Year Anniversary', 'stage_code' => 'Y1_ANNIVERSARY', 'months_after_hire' => 12, 'sort_order' => 1, 'status' => true])->json('data.id');

        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-04-29'));
        $response = $this->getJson('/api/admin/lifecycle/milestones')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reviewWindowStart', '2026-04-30')->assertJsonPath('data.0.reviewWindowEnd', '2026-07-31');
        $reviewId = $response->json('data.0.id');
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-05-01'));
        $this->getJson('/api/admin/lifecycle/milestones?all=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $reviewId)
            ->assertJsonPath('data.0.employmentType', 'Probationary');
        $pendingProbationStage = $probationStage->lifecyclePolicy->stages()->create([
            'stage_code' => 'CUSTOM', 'stage_name' => 'Custom review', 'months_after_hire' => 5,
            'sort_order' => 2, 'status' => true,
        ]);
        $this->getJson('/api/admin/lifecycle/milestones')->assertOk()->assertJsonCount(2, 'data');

        $url = "/api/admin/lifecycle/employees/{$employee->id}/employment-type-changes";
        $change = [
            'employment_id' => $employment->id,
            'previous_type_id' => $probationary->id,
            'employment_type_id' => $regular->id,
            'effective_date' => '2026-04-30',
        ];
        $this->postJson($url, $change)->assertUnprocessable()->assertJsonValidationErrors('evaluation');
        $evaluation = ['milestone_id' => $reviewId, 'status' => 'completed', 'remarks' => 'Passed evaluation'];
        $this->postJson($url, [...$change, 'evaluation' => $evaluation])
            ->assertUnprocessable()->assertJsonValidationErrors('evaluation.evaluation_document_id');
        $this->postJson($url, [...$change, 'evaluation' => [...$evaluation, 'evaluation_document_id' => null]])
            ->assertUnprocessable()->assertJsonValidationErrors('evaluation.evaluation_document_id');
        $document = LifecycleEvaluationDocument::create(['employee_id' => $employee->id, 'uploaded_by' => $employee->user_id, 'path' => 'evaluation.pdf', 'original_name' => 'evaluation.pdf', 'mime_type' => 'application/pdf', 'size' => 100]);
        $evaluation['evaluation_document_id'] = $document->id;
        $otherReview = $employee->lifecycleMilestones()->where('lifecycle_stage_id', $pendingProbationStage->id)->firstOrFail();
        $this->postJson($url, [...$change, 'evaluation' => [...$evaluation, 'milestone_id' => $otherReview->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('evaluation');
        $this->assertSame('pending', $otherReview->fresh()->status);
        $this->assertNull($otherReview->fresh()->evaluation_document_id);
        $this->assertEquals($probationary->id, $employment->fresh()->employment_type_id);
        $this->assertDatabaseCount('lifecycle_employment_type_changes', 0);

        $this->postJson($url, [...$change, 'evaluation' => $evaluation])->assertOk();
        $this->assertDatabaseHas('lifecycle_employment_type_changes', ['employee_id' => $employee->id, 'reason' => '']);

        // Completed probation history is preserved, pending probation work is replaced.
        $this->assertDatabaseHas('employee_lifecycle_milestones', [
            'id' => $reviewId, 'status' => 'completed', 'evaluation_document_id' => $document->id, 'remarks' => 'Passed evaluation',
        ]);
        $this->assertDatabaseMissing('employee_lifecycle_milestones', ['lifecycle_stage_id' => $pendingProbationStage->id]);

        $newMilestone = \App\Models\EmployeeLifecycleMilestone::where('lifecycle_stage_id', $regularStage)->firstOrFail();
        $this->assertSame('2027-01-31', $newMilestone->due_date->format('Y-m-d'));
        $this->assertSame('pending', $newMilestone->status);
        $this->getJson('/api/admin/lifecycle/milestones')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/admin/lifecycle/employees/{$employee->id}/history")->assertOk()
            ->assertJsonPath('data.pendingMilestones.0.stageName', '1st Year Anniversary')
            ->assertJsonPath('data.events.2.attachmentName', 'evaluation.pdf')
            ->assertJsonPath('data.events.2.evaluationDocumentId', $document->id)
            ->assertJsonPath('data.events.2.status', 'Completed');
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
