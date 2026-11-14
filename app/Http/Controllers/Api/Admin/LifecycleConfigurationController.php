<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmployeeLifecyclePolicy;
use App\Models\EmployeeLifecycleStage;
use App\Models\EmploymentType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
        return response()->json(['data' => EmployeeLifecyclePolicy::with(['employmentType', 'user'])->withCount('stages')->orderBy('name')->get()]);
    }

    public function savePolicy(Request $request, ?EmployeeLifecyclePolicy $policy = null): JsonResponse
    {
        $this->authorizeAdministrator($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'employment_type_id' => ['nullable', 'integer', 'exists:employment_types,id'],
            'status' => ['required', 'boolean'],
        ]);
        if ($policy?->exists) {
            $policy->update($data);
        } else {
            $policy = EmployeeLifecyclePolicy::create([...$data, 'user_id' => $request->user()->id]);
        }
        return response()->json(['data' => $policy->load(['employmentType', 'user'])->loadCount('stages')]);
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
        $policyId = $request->input('lifecycle_policy_id');
        $data = $request->validate([
            'lifecycle_policy_id' => ['required', 'integer', 'exists:employee_lifecycle_policies,id'],
            'stage_name' => ['required', 'string', 'max:255'],
            'stage_code' => ['required', 'string', 'max:255', Rule::unique('employee_lifecycle_stages')->where('lifecycle_policy_id', $policyId)->ignore($stage?->id)],
            'months_after_hire' => ['required', 'integer', 'min:0', 'max:1200'],
            'requires_attachment' => ['sometimes', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'status' => ['required', 'boolean'],
        ]);
        if ($stage?->exists) {
            if ($stage->milestones()->exists() && (int) $stage->lifecycle_policy_id !== (int) $data['lifecycle_policy_id']) {
                return response()->json(['message' => 'A stage with milestones cannot be moved to another policy.'], 422);
            }
            $stage->update($data);
        } else {
            $stage = EmployeeLifecycleStage::create($data);
        }
        return response()->json(['data' => $stage->load('lifecyclePolicy')->loadCount('milestones')]);
    }

    public function deleteStage(Request $request, EmployeeLifecycleStage $stage): JsonResponse
    {
        $this->authorizeAdministrator($request);
        if ($stage->milestones()->exists()) {
            return response()->json(['message' => 'A stage with milestones cannot be deleted. Deactivate it instead.'], 422);
        }
        $stage->delete();
        return response()->json(['message' => 'Stage deleted.']);
    }
}
