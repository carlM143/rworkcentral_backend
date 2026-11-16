<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeLifecycleMilestone;
use App\Models\EmployeeLifecycleStage;
use App\Models\EmployeeOffboarding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LifecycleWorkflowController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->role?->name === 'Administrator', 403);
    }

    private function employeeData(Employee $employee): array
    {
        $employment = $employee->latestEmployment;
        return [
            'employeeId' => $employee->id,
            'employeeNumber' => (string) $employee->emp_id,
            'employeeName' => implode(' ', array_filter([$employee->firstname, $employee->middlename, $employee->lastname, $employee->suffix])),
            'department' => $employment?->department?->department_name ?? $employee->department?->department_name ?? '—',
            'position' => $employment?->position?->position ?? '—',
            'employmentType' => $employment?->employmentType?->name ?? '—',
            'employmentId' => $employment?->id,
            'employmentTypeId' => $employment?->employment_type_id,
            'dateHired' => $employment?->date_hired?->format('Y-m-d') ?? '',
        ];
    }

    private function employeeRelations(): array
    {
        return ['department', 'latestEmployment.department', 'latestEmployment.position', 'latestEmployment.employmentType'];
    }

    public function employees(Request $request)
    {
        $this->authorizeAdmin($request);
        return response()->json(['data' => Employee::with($this->employeeRelations())->orderBy('lastname')->get()->map(fn ($e) => $this->employeeData($e))]);
    }

    private function formatMilestone(EmployeeLifecycleMilestone $m): array
    {
        return [
            ...$this->employeeData($m->employee),
            'id' => $m->id,
            'lifecycleStageId' => $m->lifecycle_stage_id,
            'requiresAttachment' => $m->lifecycleStage->requires_attachment,
            'stageName' => $m->lifecycleStage->stage_name,
            'stageCode' => $m->lifecycleStage->stage_code,
            'policyName' => $m->lifecycleStage->lifecyclePolicy->name,
            'dueDate' => $m->due_date?->format('Y-m-d'),
            'status' => $m->status,
            'remarks' => $m->remarks,
            'evaluationDocumentId' => $m->evaluation_document_id,
            'attachmentName' => $m->evaluationDocument?->original_name,
            'attachmentSize' => $m->evaluationDocument ? round($m->evaluationDocument->size / 1024).' KB' : null,
            'attachedAt' => $m->evaluationDocument?->created_at?->toISOString(),
        ];
    }

    public function milestones(Request $request)
    {
        $this->authorizeAdmin($request);
        app(\App\Services\LifecycleMilestoneSync::class)->sync();

        $today = now()->startOfDay()->toDateString();
        $dueSoonEnd = now()->startOfDay()->addDays(30)->toDateString();

        $stats = [
            'total' => EmployeeLifecycleMilestone::count(),
            'completed' => EmployeeLifecycleMilestone::where('status', 'completed')->count(),
            'pending' => EmployeeLifecycleMilestone::where('status', 'pending')->count(),
            'overdue' => EmployeeLifecycleMilestone::where('status', 'pending')
                ->whereDate('due_date', '<', $today)
                ->count(),
            'dueSoon' => EmployeeLifecycleMilestone::where('status', 'pending')
                ->whereDate('due_date', '>=', $today)
                ->whereDate('due_date', '<=', $dueSoonEnd)
                ->count(),
            'probationaryUpcoming' => EmployeeLifecycleMilestone::whereHas('lifecycleStage', fn ($q) => $q->where('stage_code', 'M6_REGULARIZATION'))
                ->whereHas('employee.latestEmployment.employmentType', fn ($q) => $q->where('name', 'Probationary'))
                ->count(),
        ];

        $departments = \App\Models\Department::orderBy('department_name')->pluck('department_name')->values();

        $relations = array_map(fn ($r) => 'employee.'.$r, $this->employeeRelations());
        $query = EmployeeLifecycleMilestone::with([...$relations, 'lifecycleStage.lifecyclePolicy', 'evaluationDocument']);

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('employee', function ($emp) use ($search) {
                    $emp->where('firstname', 'like', "%{$search}%")
                        ->orWhere('lastname', 'like', "%{$search}%")
                        ->orWhere('emp_id', 'like', "%{$search}%")
                        ->orWhereRaw("CONCAT(firstname, ' ', lastname) LIKE ?", ["%{$search}%"]);
                })->orWhereHas('lifecycleStage', function ($stage) use ($search) {
                    $stage->where('stage_name', 'like', "%{$search}%")
                        ->orWhere('stage_code', 'like', "%{$search}%");
                });
            });
        }

        if ($department = trim((string) $request->query('department', ''))) {
            $query->whereHas('employee', function ($emp) use ($department) {
                $emp->whereHas('latestEmployment.department', fn ($d) => $d->where('department_name', $department))
                    ->orWhere(function ($fallback) use ($department) {
                        $fallback->whereDoesntHave('latestEmployment.department')
                            ->whereHas('department', fn ($d) => $d->where('department_name', $department));
                    });
            });
        }

        if ($employmentType = trim((string) $request->query('employment_type', ''))) {
            $query->whereHas('employee.latestEmployment.employmentType', fn ($t) => $t->where('name', $employmentType));
        }

        $statusFilter = strtolower(trim((string) $request->query('status', '')));
        if ($statusFilter === 'completed') {
            $query->where('status', 'completed');
        } elseif ($statusFilter === 'pending') {
            $query->where('status', 'pending');
        } elseif ($statusFilter === 'overdue') {
            $query->where('status', 'pending')->whereDate('due_date', '<', $today);
        } elseif ($statusFilter === 'due soon' || $statusFilter === 'due_soon') {
            $query->where('status', 'pending')
                ->whereDate('due_date', '>=', $today)
                ->whereDate('due_date', '<=', $dueSoonEnd);
        }

        $query->orderBy('due_date')->orderBy('id');

        if ($request->boolean('all')) {
            $records = $query->get();
            return response()->json([
                'data' => $records->map(fn ($m) => $this->formatMilestone($m)),
                'stats' => $stats,
                'departments' => $departments,
            ]);
        }

        $perPage = min(max((int) $request->query('per_page', 6), 1), 100);
        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn ($m) => $this->formatMilestone($m)),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem() ?? 0,
                'to' => $paginator->lastItem() ?? 0,
            ],
            'stats' => $stats,
            'departments' => $departments,
        ]);
    }

    public function employmentTypeChanges(Request $request, Employee $employee)
    {
        $this->authorizeAdmin($request);
        $changes = DB::table('lifecycle_employment_type_changes as changes')
            ->join('users', 'users.id', '=', 'changes.changed_by')
            ->where('changes.employee_id', $employee->id)
            ->orderByDesc('changes.id')
            ->get(['changes.*', 'users.name as changed_by_name']);
        return response()->json(['data' => $changes]);
    }

    public function employeeHistory(Request $request, Employee $employee)
    {
        $this->authorizeAdmin($request);
        $employee->load($this->employeeRelations());
        $changes = DB::table('lifecycle_employment_type_changes as changes')
            ->join('users', 'users.id', '=', 'changes.changed_by')
            ->where('changes.employee_id', $employee->id)->orderBy('changes.id')
            ->get(['changes.*', 'users.name as changed_by_name']);
        $events = collect();
        foreach ($employee->employments()->with('employmentType')->orderBy('id')->get() as $employment) {
            $firstChange = $changes->firstWhere('employment_id', $employment->id);
            $initialType = $firstChange?->previous_type_name ?? $employment->employmentType?->name ?? 'Unknown';
            $events->push([
                'id' => 'employment-'.$employment->id, 'category' => 'Employment',
                'title' => 'Employment record — '.$initialType,
                'date' => $employment->date_hired?->format('Y-m-d') ?? $employment->created_at?->toISOString(),
                'description' => 'Hire date recorded for this employment. Type is based on the earliest available change history or the saved employment record.',
                'actor' => null, 'status' => $initialType,
            ]);
        }
        foreach ($changes as $change) {
            $events->push([
                'id' => 'type-change-'.$change->id, 'category' => 'Employment Type Change',
                'title' => $change->previous_type_name.' → '.$change->new_type_name,
                'date' => $change->created_at, 'description' => $change->reason,
                'actor' => $change->changed_by_name, 'status' => $change->new_type_name,
            ]);
        }
        foreach ($employee->lifecycleMilestones()->with(['lifecycleStage', 'evaluationDocument'])->where('status', 'completed')->get() as $milestone) {
            $events->push([
                'id' => 'milestone-'.$milestone->id, 'category' => 'Milestone',
                'title' => $milestone->lifecycleStage->stage_name,
                'date' => $milestone->updated_at?->toISOString(),
                'description' => $milestone->remarks ?: 'Milestone completed. Date shown is the latest saved update.',
                'actor' => null, 'status' => 'Completed',
                'attachmentName' => $milestone->evaluationDocument?->original_name,
            ]);
        }
        foreach (EmployeeOffboarding::with('user')->where('employee_id', $employee->id)->get() as $offboarding) {
            $events->push([
                'id' => 'offboarding-start-'.$offboarding->id, 'category' => 'Offboarding',
                'title' => 'Offboarding initiated — '.$offboarding->details['offboardingType'],
                'date' => $offboarding->created_at->toISOString(), 'description' => $offboarding->details['reason'],
                'actor' => $offboarding->user?->name, 'status' => $offboarding->status,
            ]);
            if ($offboarding->updated_at->ne($offboarding->created_at)) {
                $events->push([
                    'id' => 'offboarding-update-'.$offboarding->id, 'category' => 'Offboarding',
                    'title' => 'Offboarding — '.$offboarding->status,
                    'date' => $offboarding->updated_at->toISOString(),
                    'description' => 'Latest saved update. Separation date: '.$offboarding->details['separationDate'].'. Final pay: '.$offboarding->details['finalPayStatus'].'. '.($offboarding->details['remarks'] ?? ''),
                    'actor' => null, 'status' => $offboarding->status,
                ]);
            }
        }
        return response()->json(['data' => [
            'employee' => $this->employeeData($employee),
            'events' => $events->sortBy(fn ($event) => $event['date'] ? \Carbon\Carbon::parse($event['date'])->timestamp : 0)->values(),
        ]]);
    }

    public function changeEmploymentType(Request $request, Employee $employee)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'employment_id' => ['required', 'integer'],
            'previous_type_id' => ['required', 'integer'],
            'employment_type_id' => ['required', 'integer', 'exists:employment_types,id'],
            'reason' => ['required', 'string', 'max:5000'],
        ]);
        DB::transaction(function () use ($employee, $data, $request) {
            Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $employment = $employee->employments()->latest('id')->lockForUpdate()->first();
            if (!$employment) throw ValidationException::withMessages(['employment_id' => 'Create an employment record for this employee first.']);
            if ($employment->id !== (int) $data['employment_id'] || (int) $employment->employment_type_id !== (int) $data['previous_type_id']) {
                throw ValidationException::withMessages(['employment_id' => 'Employment details have changed. Reopen this window to load the current record.']);
            }
            if ((int) $employment->employment_type_id === (int) $data['employment_type_id']) {
                throw ValidationException::withMessages(['employment_type_id' => 'Select a different employment type.']);
            }
            $newType = \App\Models\EmploymentType::findOrFail($data['employment_type_id']);
            DB::table('lifecycle_employment_type_changes')->insert([
                'employee_id' => $employee->id, 'employment_id' => $employment->id,
                'changed_by' => $request->user()->id, 'previous_type_id' => $employment->employment_type_id,
                'new_type_id' => $newType->id, 'previous_type_name' => $employment->employmentType->name,
                'new_type_name' => $newType->name, 'reason' => $data['reason'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $employment->update(['employment_type_id' => $newType->id]);
        });

        $employee = $employee->fresh($this->employeeRelations());

        // Rebuild the milestone plan from the hire date and the stages of the
        // policy attached to the new employment type.
        app(\App\Services\LifecycleMilestoneSync::class)->syncEmployee($employee);

        return response()->json(['data' => $this->employeeData($employee)]);
    }

    public function createMilestone(Request $request)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'lifecycle_stage_id' => ['required', 'integer', 'exists:employee_lifecycle_stages,id', Rule::unique('employee_lifecycle_milestones')->where('employee_id', $request->input('employee_id'))],
            'due_date' => ['required', 'date'],
        ]);
        $stage = EmployeeLifecycleStage::with('lifecyclePolicy')->findOrFail($data['lifecycle_stage_id']);
        abort_unless($stage->status && $stage->lifecyclePolicy->status, 422, 'Select an active stage and policy.');
        $milestone = EmployeeLifecycleMilestone::create([...$data, 'status' => 'pending']);
        return response()->json(['data' => $milestone], 201);
    }

    public function saveMilestone(Request $request, EmployeeLifecycleMilestone $milestone)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'remarks' => ['nullable', 'string', 'max:5000'],
            'evaluation_document_id' => ['present', 'nullable', 'integer', Rule::exists('lifecycle_evaluation_documents', 'id')->where('employee_id', $milestone->employee_id)],
            'status' => ['sometimes', Rule::in(['pending', 'completed'])],
        ]);
        $requiresAttachment = $milestone->lifecycleStage->requires_attachment;
        if ($requiresAttachment && ($data['status'] ?? null) === 'completed' && !$data['evaluation_document_id']) {
            throw ValidationException::withMessages(['evaluation_document_id' => 'This stage requires an attachment to complete the milestone.']);
        }
        $status = $requiresAttachment
            ? ($data['evaluation_document_id'] ? 'completed' : 'pending')
            : ($data['status'] ?? $milestone->status);
        $milestone->update([...$data, 'status' => $status]);
        return response()->json(['data' => $milestone]);
    }

    private function offboardingData(EmployeeOffboarding $record): array
    {
        $completed = count(array_filter($record->details['checklist']));
        return [
            ...$record->details, ...$this->employeeData($record->employee),
            'id' => $record->id, 'offboardingNumber' => 'OFF-'.str_pad((string) $record->id, 6, '0', STR_PAD_LEFT),
            'status' => $record->status, 'initiatedBy' => $record->user?->name ?? 'Administrator',
            'completedTasks' => $completed, 'totalTasks' => 6, 'clearanceProgress' => (int) round($completed / 6 * 100),
            'createdAt' => $record->created_at->toISOString(), 'updatedAt' => $record->updated_at->toISOString(),
        ];
    }

    public function offboardings(Request $request)
    {
        $this->authorizeAdmin($request);
        $relations = array_map(fn ($r) => 'employee.'.$r, $this->employeeRelations());
        return response()->json(['data' => EmployeeOffboarding::with([...$relations, 'user'])->latest()->get()->map(fn ($r) => $this->offboardingData($r))]);
    }

    public function saveOffboarding(Request $request, ?EmployeeOffboarding $offboarding = null)
    {
        $this->authorizeAdmin($request);
        $keys = ['hrClearance', 'departmentClearance', 'financeClearance', 'itClearance', 'assetReturn', 'exitInterview'];
        $rules = [
            'employeeId' => ['required', 'integer', 'exists:employees,id'],
            'offboardingType' => ['required', Rule::in(['Resignation', 'End of Contract', 'Retirement', 'Termination', 'Redundancy'])],
            'noticeDate' => ['required', 'date'],
            'lastWorkingDay' => ['required', 'date', 'after_or_equal:noticeDate'],
            'separationDate' => ['required', 'date', 'after_or_equal:lastWorkingDay'],
            'exitInterviewDate' => ['nullable', 'date', 'after_or_equal:noticeDate'],
            'reason' => ['required', 'string', 'max:5000'], 'remarks' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::in(['Draft', 'Pending Clearance', 'Clearance In Progress', 'For Review', 'Ready for Final Pay', 'Completed', 'Cancelled'])],
            'finalPayStatus' => ['required', Rule::in(['Not Started', 'For Processing', 'For Review', 'Ready for Release', 'Released'])],
            'checklist' => ['required', 'array:'.implode(',', $keys)],
        ];
        foreach ($keys as $key) $rules['checklist.'.$key] = ['required', 'boolean'];
        $data = $request->validate($rules);
        $record = DB::transaction(function () use ($data, $offboarding, $request) {
            Employee::whereKey($data['employeeId'])->lockForUpdate()->firstOrFail();
            if ($offboarding?->exists) {
                $offboarding = EmployeeOffboarding::whereKey($offboarding->id)->lockForUpdate()->firstOrFail();
                if ($offboarding->employee_id !== $data['employeeId'] || in_array($offboarding->status, ['Completed', 'Cancelled'])) {
                    throw ValidationException::withMessages(['status' => 'Closed records and employee identity cannot be changed.']);
                }
            }
            $active = EmployeeOffboarding::where('employee_id', $data['employeeId'])->whereNotIn('status', ['Completed', 'Cancelled']);
            if ($offboarding?->exists) $active->where('id', '!=', $offboarding->id);
            if ($active->exists()) throw ValidationException::withMessages(['employeeId' => 'This employee already has an active offboarding.']);
            if (in_array($data['status'], ['Ready for Final Pay', 'Completed']) && count(array_filter($data['checklist'])) !== 6) {
                throw ValidationException::withMessages(['checklist' => 'Complete all clearance tasks first.']);
            }
            if ($data['status'] === 'Completed' && $data['finalPayStatus'] !== 'Released') {
                throw ValidationException::withMessages(['finalPayStatus' => 'Final pay must be released before completing offboarding.']);
            }
            $details = $data;
            unset($details['employeeId'], $details['status']);
            $values = ['employee_id' => $data['employeeId'], 'status' => $data['status'], 'details' => $details];
            if ($offboarding?->exists) $offboarding->update($values);
            else $offboarding = EmployeeOffboarding::create([...$values, 'user_id' => $request->user()->id]);
            return $offboarding;
        });
        $record->load(['employee', 'user']);
        return response()->json(['data' => $this->offboardingData($record)]);
    }
}
