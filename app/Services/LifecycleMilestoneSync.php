<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeLifecycleMilestone;
use App\Models\EmployeeLifecycleStage;
use Illuminate\Support\Collection;

class LifecycleMilestoneSync
{
    /**
     * Active stages whose parent policy is also active, with the policy loaded.
     *
     * @return Collection<int, EmployeeLifecycleStage>
     */
    private function activeStages(): Collection
    {
        return EmployeeLifecycleStage::with('lifecyclePolicy')
            ->where('status', true)
            ->whereHas('lifecyclePolicy', fn ($query) => $query->where('status', true))
            ->get();
    }

    public function sync(): void
    {
        $stages = $this->activeStages();

        Employee::with('latestEmployment')->chunkById(200, function ($employees) use ($stages) {
            foreach ($employees as $employee) {
                $this->syncEmployee($employee, $stages);
            }
        });
    }

    /**
     * Create the milestones the employee should have based on their hire date,
     * employment type and the active policy stages.
     *
     * Pending milestones that no longer belong to the employee's current plan
     * are removed so the milestone list always matches the latest employment
     * type. Completed milestones and uploaded evaluations are always kept.
     *
     * @param  Collection<int, EmployeeLifecycleStage>|null  $stages
     */
    public function syncEmployee(Employee $employee, ?Collection $stages = null, bool $pruneObsolete = true): void
    {
        $employee->loadMissing('latestEmployment');
        $employment = $employee->latestEmployment;

        if (!$employment?->date_hired) {
            return;
        }

        $stages ??= $this->activeStages();

        $applicable = $stages->filter(function (EmployeeLifecycleStage $stage) use ($employment) {
            $typeId = $stage->lifecyclePolicy?->employment_type_id;

            return $typeId === null || (int) $typeId === (int) $employment->employment_type_id;
        })->values();

        foreach ($applicable as $stage) {
            // The employee/stage unique key prevents duplicates, and existing
            // evaluations, remarks and dates are never overwritten.
            EmployeeLifecycleMilestone::firstOrCreate([
                'employee_id' => $employee->id,
                'lifecycle_stage_id' => $stage->id,
            ], [
                'due_date' => $employment->date_hired->copy()->addMonthsNoOverflow((int) $stage->months_after_hire),
                'status' => 'pending',
            ]);
        }

        if (!$pruneObsolete) {
            return;
        }

        $obsolete = EmployeeLifecycleMilestone::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'pending')
            ->whereNull('evaluation_document_id');

        if ($applicable->isNotEmpty()) {
            $obsolete->whereNotIn('lifecycle_stage_id', $applicable->pluck('id'));
        }

        $obsolete->delete();
    }
}
