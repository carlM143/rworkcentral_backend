<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\EmployeeLifecyclePolicy;
use App\Models\EmployeeLifecycleStage;
use App\Models\EmploymentType;
use App\Models\User;
use App\Services\LifecycleMilestoneSync;
use Illuminate\Database\Seeder;

class EmployeeLifecycleSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::whereHas('role', fn ($query) => $query->where('name', 'Administrator'))->first()
            ?? User::first();
        $probationary = EmploymentType::where('name', 'Probationary')->first();

        if (!$admin || !$probationary) {
            $this->command?->warn('Probationary regularization seeding skipped: a user and the Probationary employment type are required.');

            return;
        }

        $policies = EmployeeLifecyclePolicy::where('employment_type_id', $probationary->id)
            ->orderBy('id')->get();
        $policy = $policies->first(fn ($item) => $item->regularizationStage()->exists())
            ?? $policies->first(fn ($item) => $this->isRegularizationName($item->name))
            ?? EmployeeLifecyclePolicy::firstOrNew([
                'name' => EmployeeLifecycleStage::REGULARIZATION_POLICY,
                'employment_type_id' => $probationary->id,
            ]);
        if (!$policy->exists) {
            $policy->fill([
                'description' => 'Review window for manual regularization after hire.',
                'status' => true,
            ]);
        }
        $policy->user_id ??= $admin->id;
        $policy->save();

        $policy->stages()->whereIn('stage_code', EmployeeLifecycleStage::RETIRED_REGULARIZATION_CODES)
            ->update(['status' => false]);

        // Reuse the existing review record so its evaluations stay attached.
        $review = $policy->regularizationStage()->first()
            ?? $policy->stages()->where('stage_code', EmployeeLifecycleStage::REGULARIZATION_CODE)->first()
            ?? $policy->stages()->orderBy('id')->get()->first(
                fn ($stage) => $this->isRegularizationName($stage->stage_name),
            );
        if (!$review) {
            $review = $policy->stages()->create([
                'stage_code' => EmployeeLifecycleStage::REGULARIZATION_CODE,
                'stage_name' => 'Probationary to Regular',
                'months_after_hire' => EmployeeLifecycleStage::REGULARIZATION_WINDOW_START,
                'end_month_after_hire' => EmployeeLifecycleStage::REGULARIZATION_WINDOW_END,
                'is_managed_regularization' => true,
                'sort_order' => 1,
                'requires_attachment' => true,
                'status' => true,
            ]);
        } elseif (!$review->is_managed_regularization) {
            $review->update(['is_managed_regularization' => true]);
        }
        $review->setRelation('lifecyclePolicy', $policy);
        $stages = collect([$review]);

        // Seed only this plan. Keep existing milestones and leave the employment
        // type, effective date, and change history to the manual HR workflow.
        if ($policy->status && $review->status) {
            $sync = app(LifecycleMilestoneSync::class);
            Employee::with('latestEmployment')
                ->whereHas('latestEmployment', fn ($query) => $query->where('employment_type_id', $probationary->id))
                ->chunkById(200, function ($employees) use ($sync, $stages) {
                    foreach ($employees as $employee) {
                        $sync->syncEmployee($employee, $stages, pruneObsolete: false);
                    }
                });
        }

        $this->command?->info('Probationary regularization policy and milestones are ready.');
    }

    private function isRegularizationName(string $name): bool
    {
        $name = strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', $name)));

        return in_array($name, [
            'probi to regular',
            'probationary to regular',
            'probationary regularization policy',
        ], true);
    }
}
