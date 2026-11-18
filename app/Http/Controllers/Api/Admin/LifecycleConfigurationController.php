<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmployeeLifecyclePolicy;
use App\Models\EmployeeLifecycleStage;
use App\Models\EmploymentType;
use App\Services\LifecycleMilestoneSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LifecycleConfigurationController extends Controller
{
    private function authorizeAdministrator(Request $request): void
    {
        abort_unless($request->user()?->role?->name === 'Administrator', 403);
    }

    public function employmentTypes(Request $request): JsonResponse
    {
        $this->authorizeAdministrator($request);
        return response()->json(['data' => EmploymentType::orderBy('name')->get(['id', 'name'])]);
    }

    public function policies(Request $request): JsonResponse
    {
        $this->authorizeAdministrator($request);
        return response()->json(['data' => EmployeeLifecyclePolicy::with(['employmentType', 'user', 'regularizationStage'])
            ->withCount('stages')->orderBy('name')->get()]);
    }

    public function savePolicy(Request $request, ?EmployeeLifecyclePolicy $policy = null): JsonResponse
    {
        $this->authorizeAdministrator($request);
        $hasWindow = $policy?->regularizationStage()->exists() ?? false;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'employment_type_id' => ['nullable', 'integer', 'exists:employment_types,id'],
            'status' => ['required', 'boolean'],
        ]);
        if ($hasWindow && array_key_exists('employment_type_id', $data)
            && (int) $data['employment_type_id'] !== (int) $policy->employment_type_id) {
            throw ValidationException::withMessages(['employment_type_id' => 'The regularization policy must stay linked to the probationary employment type.']);
        }
        if ($policy?->exists) {
            $policy->update($data);
        } else {
            $policy = EmployeeLifecyclePolicy::create([...$data, 'user_id' => $request->user()->id]);
        }
        return response()->json(['data' => $policy->load(['employmentType', 'user', 'regularizationStage'])->loadCount('stages')]);
    }

    public function deletePolicy(Request $request, EmployeeLifecyclePolicy $policy): JsonResponse
    {
        $this->authorizeAdministrator($request);
        if ($policy->stages()->exists()) {
            return response()->json(['message' => 'A policy with stages cannot be deleted. Deactivate it instead.'], 422);
        }
        $policy->delete();
        return response()->json(['message' => 'Policy deleted.']);
    }

    public function stages(Request $request): JsonResponse
    {
        $this->authorizeAdministrator($request);
        return response()->json(['data' => EmployeeLifecycleStage::with('lifecyclePolicy')->withCount('milestones')->orderBy('sort_order')->orderBy('id')->get()]);
    }

    public function saveStage(Request $request, ?EmployeeLifecycleStage $stage = null): JsonResponse
    {
        $this->authorizeAdministrator($request);
        $data = $request->validate([
            'lifecycle_policy_id' => ['required', 'integer', 'exists:employee_lifecycle_policies,id'],
            'stage_name' => ['required', 'string', 'max:255'],
            'months_after_hire' => ['required', 'integer', 'min:0', 'max:1200'],
            'end_month_after_hire' => ['nullable', 'integer', 'min:0', 'max:1200', 'gte:months_after_hire'],
            'requires_attachment' => ['sometimes', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'status' => ['required', 'boolean'],
        ]);
        $data['end_month_after_hire'] ??= null;
        $stage = DB::transaction(function () use ($stage, $data) {
            // Serialize code allocation for this policy. The database also enforces uniqueness.
            $policy = EmployeeLifecyclePolicy::query()->lockForUpdate()->findOrFail($data['lifecycle_policy_id']);
            if (!$stage?->exists) {
                $slug = substr(Str::upper(Str::slug($data['stage_name'], '_')), 0, 200);
                $base = 'STG_'.($slug === '' ? 'STAGE' : $slug);
                $code = $base;
                for ($suffix = 2; $policy->stages()->where('stage_code', $code)->exists(); $suffix++) {
                    $code = $base.'_'.$suffix;
                }

                return $policy->stages()->create([...$data, 'stage_code' => $code]);
            }

            if ((int) $stage->lifecycle_policy_id !== (int) $policy->id) {
                if ($stage->is_managed_regularization) {
                    throw ValidationException::withMessages(['lifecycle_policy_id' => 'The regularization stage cannot be moved to another policy.']);
                }
                if ($stage->milestones()->exists()) {
                    throw ValidationException::withMessages(['lifecycle_policy_id' => 'A stage with milestones cannot be moved to another policy.']);
                }
                if ($policy->stages()->where('stage_code', $stage->stage_code)->exists()) {
                    throw ValidationException::withMessages(['lifecycle_policy_id' => 'This policy already contains a stage with the same code.']);
                }
            }

            $this->updateStage($stage, $data);

            return $stage;
        });
        return response()->json(['data' => $stage->load('lifecyclePolicy')->loadCount('milestones')]);
    }

    private function updateStage(EmployeeLifecycleStage $stage, array $data): void
    {
        $stage->update($data);
        if ($stage->wasChanged(['months_after_hire', 'end_month_after_hire'])) {
            app(LifecycleMilestoneSync::class)->refreshPendingStageDates($stage);
        }
    }

    public function deleteStage(Request $request, EmployeeLifecycleStage $stage): JsonResponse
    {
        $this->authorizeAdministrator($request);
        if ($stage->is_managed_regularization) {
            return response()->json(['message' => 'The regularization stage cannot be deleted. Deactivate it instead.'], 422);
        }
        if ($stage->milestones()->exists()) {
            return response()->json(['message' => 'A stage with milestones cannot be deleted. Deactivate it instead.'], 422);
        }
        $stage->delete();
        return response()->json(['message' => 'Stage deleted.']);
    }
}
