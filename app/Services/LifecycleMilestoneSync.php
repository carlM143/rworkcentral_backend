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

    public function refreshPendingStageDates(EmployeeLifecycleStage $stage): void
    {
        $typeId = $stage->lifecyclePolicy()->value('employment_type_id');
        $stage->milestones()->where('status', 'pending')->with('employee.latestEmployment')
            ->chunkById(200, function ($milestones) use ($stage, $typeId) {
                foreach ($milestones as $milestone) {
                    $employment = $milestone->employee?->latestEmployment;
                    if (!$employment?->date_hired || ($typeId !== null && (int) $typeId !== (int) $employment->employment_type_id)) {
                        continue;
                    }
                    $milestone->update([
                        'due_date' => $employment->date_hired->copy()->addMonthsNoOverflow($stage->targetMonthAfterHire()),
                    ]);
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
            $targetDate = $employment->date_hired->copy()->addMonthsNoOverflow($stage->targetMonthAfterHire());

            $milestone = EmployeeLifecycleMilestone::firstOrCreate([
                'employee_id' => $employee->id,
                'lifecycle_stage_id' => $stage->id,
            ], [
                'due_date' => $targetDate,
                'status' => 'pending',
            ]);
            if ($stage->is_managed_regularization && $milestone->status === 'pending'
                && $milestone->due_date?->toDateString() !== $targetDate->toDateString()) {
                $milestone->update(['due_date' => $targetDate]);
            }
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
