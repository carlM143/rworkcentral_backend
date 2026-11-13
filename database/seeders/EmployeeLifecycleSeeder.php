<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\EmployeeLifecycleMilestone;
use App\Models\EmployeeLifecyclePolicy;
use App\Models\EmployeeLifecycleStage;
use App\Models\EmploymentType;
use App\Models\User;
use App\Services\LifecycleMilestoneSync;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EmployeeLifecycleSeeder extends Seeder
{
    /**
     * Seed the lifecycle configuration that drives employee milestones and
     * the demo history shown on the Employee History timeline.
     */
    public function run(): void
    {
        $admin = User::whereHas('role', fn ($query) => $query->where('name', 'Administrator'))->first()
            ?? User::first();

        if (!$admin) {
            $this->command?->warn('Employee lifecycle seeding skipped: no users found.');

            return;
        }

        // The EmploymentTypeSeeder normally runs first, but resolving the types
        // here keeps this seeder safe to run on its own.
        $types = EmploymentType::whereIn('name', [
            'Part-Time', 'Contractual', 'Regular', 'Probationary', 'Training',
        ])->pluck('id', 'name');

        $policies = $this->seedPolicies($admin, $types);
        $this->seedEmploymentTypeHistory($admin, $types, $policies);

        // Builds each employee's milestones from their latest employment type,
        // hire date and the matching policy stages. Existing milestones and
        // evaluations are preserved.
        app(LifecycleMilestoneSync::class)->sync();

        $this->command?->info(sprintf(
            'Employee lifecycle ready: %d policies, %d stages, %d milestones, %d recorded type changes.',
            EmployeeLifecyclePolicy::count(),
            EmployeeLifecycleStage::count(),
            EmployeeLifecycleMilestone::count(),
            DB::table('lifecycle_employment_type_changes')->count(),
        ));
    }

    /**
     * @param  \Illuminate\Support\Collection<string, int>  $types
     * @return array<string, EmployeeLifecyclePolicy>
     */
    private function seedPolicies(User $admin, $types): array
    {
        $saved = [];

        foreach ($this->policyDefinitions($types) as $definition) {
            $policy = EmployeeLifecyclePolicy::updateOrCreate(
                ['name' => $definition['name']],
                [
                    'user_id' => $admin->id,
                    'employment_type_id' => $definition['employment_type_id'],
                    'description' => $definition['description'],
                    'status' => true,
                ]
            );

            foreach ($definition['stages'] as $stage) {
                EmployeeLifecycleStage::updateOrCreate(
                    [
                        'lifecycle_policy_id' => $policy->id,
                        'stage_code' => $stage['stage_code'],
                    ],
                    [...$stage, 'status' => true]
                );
            }

            $saved[$definition['key']] = $policy;
        }

        return $saved;
    }

    /**
     * @param  \Illuminate\Support\Collection<string, int>  $types
     * @return array<int, array<string, mixed>>
     */
    private function policyDefinitions($types): array
    {
        return [
            [
                'key' => 'probationary',
                'name' => 'Probationary Regularization Policy',
                'employment_type_id' => $types['Probationary'] ?? null,
                'description' => 'Mandatory performance checkpoints and regularization evaluation for all probationary employees across 6 months.',
                'stages' => [
                    [
                        'stage_name' => '1st Month Check-in',
                        'stage_code' => 'M1_CHECKIN',
                        'months_after_hire' => 1,
                        'sort_order' => 1,
                        'requires_attachment' => false,
                    ],
                    [
                        'stage_name' => '3rd Month Mid-Probationary Evaluation',
                        'stage_code' => 'M3_EVAL',
                        'months_after_hire' => 3,
                        'sort_order' => 2,
                        'requires_attachment' => true,
                    ],
                    [
                        'stage_name' => '5th Month Pre-Regularization Review',
                        'stage_code' => 'M5_REVIEW',
                        'months_after_hire' => 5,
                        'sort_order' => 3,
                        'requires_attachment' => true,
                    ],
                    [
                        'stage_name' => '6th Month Regularization Milestone',
                        'stage_code' => 'M6_REGULARIZATION',
                        'months_after_hire' => 6,
                        'sort_order' => 4,
                        'requires_attachment' => true,
                    ],
                ],
            ],
            [
                'key' => 'regular',
                'name' => 'Regular Employee Career & Milestone Policy',
                'employment_type_id' => $types['Regular'] ?? null,
                'description' => 'Annual performance evaluations, service anniversary recognitions, and career milestones for regular employees.',
                'stages' => [
                    [
                        'stage_name' => '1st Year Service Anniversary',
                        'stage_code' => 'Y1_ANNIVERSARY',
                        'months_after_hire' => 12,
                        'sort_order' => 1,
                        // Recognition milestone: completing it needs no document.
                        'requires_attachment' => false,
                    ],
                    [
                        'stage_name' => '2nd Year Career Progression Review',
                        'stage_code' => 'Y2_CAREER_REVIEW',
                        'months_after_hire' => 24,
                        'sort_order' => 2,
                        'requires_attachment' => true,
                    ],
                    [
                        'stage_name' => '3rd Year Seniority & Leadership Evaluation',
                        'stage_code' => 'Y3_SENIORITY',
                        'months_after_hire' => 36,
                        'sort_order' => 3,
                        'requires_attachment' => true,
                    ],
                ],
            ],
            [
                'key' => 'contractual',
                'name' => 'Contractual Review & Renewal Policy',
                'employment_type_id' => $types['Contractual'] ?? null,
                'description' => 'Periodic milestone reviews for contractual staff leading up to contract renewal, conversion or separation.',
                'stages' => [
                    [
                        'stage_name' => 'Mid-Contract Assessment',
                        'stage_code' => 'MID_CONTRACT_EVAL',
                        'months_after_hire' => 3,
                        'sort_order' => 1,
                        'requires_attachment' => true,
                    ],
                    [
                        'stage_name' => 'Contract Renewal & Transition Review',
                        'stage_code' => 'CONTRACT_RENEWAL',
                        'months_after_hire' => 5,
                        'sort_order' => 2,
                        'requires_attachment' => true,
                    ],
                ],
            ],
            [
                'key' => 'part_time',
                'name' => 'Part-Time Integration Check-in Policy',
                'employment_type_id' => $types['Part-Time'] ?? null,
                'description' => 'Lightweight check-in for part-time employees to confirm schedule fit and onboarding completion.',
                'stages' => [
                    [
                        'stage_name' => '1st Month Part-Time Check-in',
                        'stage_code' => 'PT_M1_CHECKIN',
                        'months_after_hire' => 1,
                        'sort_order' => 1,
                        'requires_attachment' => false,
                    ],
                ],
            ],
            [
                'key' => 'training',
                'name' => 'Training Completion Review Policy',
                'employment_type_id' => $types['Training'] ?? null,
                'description' => 'Tracks training completion before an employee moves to a regular or contractual employment type.',
                'stages' => [
                    [
                        'stage_name' => 'Training Completion Review',
                        'stage_code' => 'TRAINING_COMPLETION',
                        'months_after_hire' => 1,
                        'sort_order' => 1,
                        'requires_attachment' => true,
                    ],
                ],
            ],
        ];
    }

    /**
     * Backfill a realistic Probationary → Regular progression for employees
     * who are already regular so the Employee History timeline has content.
     *
     * @param  \Illuminate\Support\Collection<string, int>  $types
     * @param  array<string, EmployeeLifecyclePolicy>  $policies
     */
    private function seedEmploymentTypeHistory(User $admin, $types, array $policies): void
    {
        $probationaryId = $types['Probationary'] ?? null;
        $regularId = $types['Regular'] ?? null;

        if (!$probationaryId || !$regularId) {
            return;
        }

        $regularEmployees = Employee::with('latestEmployment')
            ->whereHas('latestEmployment', fn ($query) => $query->where('employment_type_id', $regularId)->whereNotNull('date_hired'))
            ->orderBy('id')
            ->get();

        $regularizationStage = ($policies['probationary'] ?? null)?->stages()
            ->where('stage_code', 'M6_REGULARIZATION')
            ->first();

        foreach ($regularEmployees as $employee) {
            $employment = $employee->latestEmployment;
            $regularizationDate = Carbon::parse($employment->date_hired)->addMonthsNoOverflow(6);

            // Never record a regularization that has not happened yet.
            if ($regularizationDate->isFuture()) {
                continue;
            }

            $alreadyRecorded = DB::table('lifecycle_employment_type_changes')
                ->where('employee_id', $employee->id)
                ->where('employment_id', $employment->id)
                ->where('previous_type_id', $probationaryId)
                ->where('new_type_id', $regularId)
                ->exists();

            if (!$alreadyRecorded) {
                DB::table('lifecycle_employment_type_changes')->insert([
                    'employee_id' => $employee->id,
                    'employment_id' => $employment->id,
                    'changed_by' => $admin->id,
                    'previous_type_id' => $probationaryId,
                    'new_type_id' => $regularId,
                    'previous_type_name' => 'Probationary',
                    'new_type_name' => 'Regular',
                    'reason' => 'Completed the probationary evaluation and was recommended for regularization.',
                    'created_at' => $regularizationDate,
                    'updated_at' => $regularizationDate,
                ]);
            }

            if (!$regularizationStage) {
                continue;
            }

            $milestone = EmployeeLifecycleMilestone::firstOrCreate(
                [
                    'employee_id' => $employee->id,
                    'lifecycle_stage_id' => $regularizationStage->id,
                ],
                [
                    'due_date' => $regularizationDate->toDateString(),
                    'status' => 'completed',
                    'remarks' => 'Regularization evaluation passed. Employment type updated to Regular.',
                ]
            );

            // Backdate the completion so it appears in order on the timeline.
            if ($milestone->wasRecentlyCreated) {
                DB::table('employee_lifecycle_milestones')->where('id', $milestone->id)->update([
                    'created_at' => $regularizationDate,
                    'updated_at' => $regularizationDate,
                ]);
            }
        }
    }
}
