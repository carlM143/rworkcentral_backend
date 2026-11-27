<?php

namespace App\Http\Controllers\Api\Employee;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ObaEmployee;
use App\Models\ObaRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ObaRequestcontroller extends Controller
{
    private const RELATIONS = ['obaEmployees', 'images', 'filer:id,firstname,lastname'];

    public function index(Request $request): JsonResponse
    {
        $employee = $this->resolveEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $obaRequests = ObaRequest::with(self::RELATIONS)
            ->where(function ($query) use ($employee) {
                $query->where('employee_id', $employee->id)
                    ->orWhereHas('obaEmployees', fn ($q) => $q->where('employee_id', $employee->id));
            })
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->status)
            )
            ->orderByDesc('date_request')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15))
            ->through(fn (ObaRequest $r) => $this->present($r, $employee));

        return response()->json([
            'success' => true,
            'message' => 'OBA requests retrieved successfully.',
            'data' => $obaRequests,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $employee = $this->resolveEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'destination' => ['required', 'string', 'max:255'],
            'date_request' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'time_from' => ['required', 'date_format:H:i'],
            'time_to' => ['required', 'date_format:H:i', 'after:time_from'],
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'distinct', 'exists:employees,id'],
            'images' => ['nullable', 'array', 'max:5'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ]);

        $timeFrom = Carbon::createFromFormat('Y-m-d H:i', $validated['date_request'].' '.$validated['time_from']);
        $timeTo = Carbon::createFromFormat('Y-m-d H:i', $validated['date_request'].' '.$validated['time_to']);

        $employeeIds = collect($validated['employee_ids'])->unique()->values();

        $conflicts = $this->conflictingNames($employeeIds->all(), $timeFrom, $timeTo);

        if ($conflicts->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'These employees already have an OBA that overlaps this time: '.$conflicts->implode(', ').'.',
            ], 422);
        }

        $storedPaths = [];

        try {
            $obaRequest = DB::transaction(function () use (
                $request,
                $validated,
                $employee,
                $employeeIds,
                $timeFrom,
                $timeTo,
                &$storedPaths
            ) {
                $obaRequest = ObaRequest::create([
                    'employee_id' => $employee->id,
                    'dept_head_id' => null,
                    'dept_head_acted_at' => null,
                    'reason' => $validated['reason'],
                    'destination' => $validated['destination'],
                    'time_from' => $timeFrom,
                    'time_to' => $timeTo,
                    'date_request' => $validated['date_request'],
                    'status' => ObaRequest::STATUS_PENDING,
                ]);

                \App\Support\DepartmentRequestApprovals::initialize('oba', (object) $obaRequest->getAttributes());
                if (! \App\Support\DepartmentRequestApprovals::records('oba', $obaRequest->id)->exists()) {
                    $obaRequest->update(['status' => ObaRequest::STATUS_DEPT_HEAD_APPROVED]);
                }

                Employee::whereIn('id', $employeeIds)
                    ->get(['id', 'firstname', 'lastname'])
                    ->each(function (Employee $emp) use ($obaRequest) {
                        $obaRequest->obaEmployees()->create([
                            'employee_id' => $emp->id,
                            'employee_name' => trim($emp->firstname.' '.$emp->lastname),
                        ]);
                    });

                foreach ($request->file('images', []) as $file) {
                    $path = $file->store('oba_images', 'public');
                    $storedPaths[] = $path;

                    $obaRequest->images()->create(['image_path' => $path]);
                }

                return $obaRequest;
            });
        } catch (\Throwable $e) {
            foreach ($storedPaths as $path) {
                Storage::disk('public')->delete($path);
            }

            throw $e;
        }

        return response()->json([
            'success' => true,
            'message' => 'OBA request submitted successfully.',
            'data' => $this->present($obaRequest->load(self::RELATIONS), $employee),
        ], 201);
    }

    public function show(Request $request, ObaRequest $obaRequest): JsonResponse
    {
        $employee = $this->resolveEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $canView = $obaRequest->employee_id === $employee->id
            || $obaRequest->obaEmployees()->where('employee_id', $employee->id)->exists();

        if (! $canView) {
            return response()->json([
                'success' => false,
                'message' => 'OBA request not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'OBA request retrieved successfully.',
            'data' => $this->present($obaRequest->load(self::RELATIONS), $employee),
        ]);
    }

    public function cancel(Request $request, ObaRequest $obaRequest): JsonResponse
    {
        $employee = $this->resolveEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        if ($error = $this->guardFiler($obaRequest, $employee, [
            ObaRequest::STATUS_PENDING,
            ObaRequest::STATUS_DEPT_HEAD_APPROVED,
        ], 'This OBA request can no longer be cancelled.')) {
            return $error;
        }

        $obaRequest->update(['status' => ObaRequest::STATUS_CANCELLED]);

        return response()->json([
            'success' => true,
            'message' => 'OBA request cancelled successfully.',
            'data' => $this->present($obaRequest->fresh(self::RELATIONS), $employee),
        ]);
    }

    // GET /oba-requests/departments
    public function departments(Request $request): JsonResponse
    {
        $employee = $this->resolveEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $companyId = $employee->department?->company_id;

        $departments = Department::query()
            ->where('status', true)
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->orderBy('department_name')
            ->get(['id', 'department_name'])
            ->map(fn (Department $d) => [
                'id' => $d->id,
                'name' => $d->department_name,
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Departments retrieved successfully.',
            'data' => $departments,
        ]);
    }

    // GET /oba-requests/available-employees?department_id=1
    public function availableEmployees(Request $request): JsonResponse
    {
        $employee = $this->resolveEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $request->validate([
            'department_id' => ['required', 'integer', 'exists:departments,id'],
        ]);

        $companyId = $employee->department?->company_id;

        // The filer is no longer excluded: they can include themselves like anyone else.
        $employees = Employee::query()
            ->where('department_id', $request->integer('department_id'))
            ->where('status', true)
            ->when($companyId, fn ($query) => $query->whereHas(
                'department',
                fn ($q) => $q->where('company_id', $companyId)
            ))
            ->orderBy('firstname')
            ->orderBy('lastname')
            ->get(['id', 'firstname', 'lastname'])
            ->map(fn (Employee $e) => [
                'id' => $e->id,
                'name' => trim($e->firstname.' '.$e->lastname),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Employees retrieved successfully.',
            'data' => $employees,
        ]);
    }

    // POST /oba-requests/{obaRequest}/employees
    public function addEmployees(Request $request, ObaRequest $obaRequest): JsonResponse
    {
        $employee = $this->resolveEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        if ($error = $this->guardFiler($obaRequest, $employee, [ObaRequest::STATUS_PENDING],
            'Employees can only be changed before the department head approves.')) {
            return $error;
        }

        $validated = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'distinct', 'exists:employees,id'],
        ]);

        $existingIds = $obaRequest->obaEmployees()->pluck('employee_id');
        $newIds = collect($validated['employee_ids'])->diff($existingIds)->values();

        $conflicts = $this->conflictingNames(
            $newIds->all(),
            $obaRequest->time_from,
            $obaRequest->time_to,
            $obaRequest->id
        );

        if ($conflicts->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'These employees already have an OBA that overlaps this time: '.$conflicts->implode(', ').'.',
            ], 422);
        }

        Employee::whereIn('id', $newIds)
            ->get(['id', 'firstname', 'lastname'])
            ->each(function (Employee $emp) use ($obaRequest) {
                $obaRequest->obaEmployees()->create([
                    'employee_id' => $emp->id,
                    'employee_name' => trim($emp->firstname.' '.$emp->lastname),
                ]);
            });

        return response()->json([
            'success' => true,
            'message' => 'Employees added successfully.',
            'data' => $this->present($obaRequest->load(self::RELATIONS), $employee),
        ]);
    }

    // DELETE /oba-requests/{obaRequest}/employees/{obaEmployee}
    public function removeEmployee(
        Request $request,
        ObaRequest $obaRequest,
        ObaEmployee $obaEmployee
    ): JsonResponse {
        $employee = $this->resolveEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        if ($error = $this->guardFiler($obaRequest, $employee, [ObaRequest::STATUS_PENDING],
            'Employees can only be changed before the department head approves.')) {
            return $error;
        }

        if ($obaEmployee->oba_request_id !== $obaRequest->id) {
            return response()->json([
                'success' => false,
                'message' => 'Employee not found on this OBA request.',
            ], 404);
        }

        if ($obaRequest->obaEmployees()->count() <= 1) {
            return response()->json([
                'success' => false,
                'message' => 'An OBA request needs at least one employee.',
            ], 422);
        }

        $obaEmployee->delete();

        return response()->json([
            'success' => true,
            'message' => 'Employee removed successfully.',
            'data' => $this->present($obaRequest->load(self::RELATIONS), $employee),
        ]);
    }

    private function resolveEmployee(Request $request): Employee|JsonResponse
    {
        $user = $request->user();

        abort_unless($user, 401, 'Unauthenticated.');

        $employee = Employee::where('user_id', $user->id)->first();

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee record not found.',
            ], 404);
        }

        return $employee;
    }

    /** Adds is_filer so the frontend knows whether to show cancel/edit controls. */
    private function present(ObaRequest $obaRequest, Employee $employee): ObaRequest
    {
        $obaRequest->setAttribute('is_filer', $obaRequest->employee_id === $employee->id);

        return $obaRequest;
    }

    /** Only the filer may act, and only while the status is one of $allowedStatuses. */
    private function guardFiler(
        ObaRequest $obaRequest,
        Employee $employee,
        array $allowedStatuses,
        string $statusMessage
    ): ?JsonResponse {
        if ($obaRequest->employee_id !== $employee->id) {
            return response()->json([
                'success' => false,
                'message' => 'Only the employee who filed this OBA request can do that.',
            ], 403);
        }

        if (! in_array($obaRequest->status, $allowedStatuses, true)) {
            return response()->json([
                'success' => false,
                'message' => $statusMessage,
            ], 422);
        }

        return null;
    }

    /** Names of employees who already have an overlapping active OBA. */
    private function conflictingNames(array $employeeIds, Carbon $from, Carbon $to, ?int $ignoreRequestId = null)
    {
        if (empty($employeeIds)) {
            return collect();
        }

        return ObaEmployee::whereIn('employee_id', $employeeIds)
            ->whereHas('obaRequest', function ($query) use ($from, $to, $ignoreRequestId) {
                $query->whereIn('status', ObaRequest::ACTIVE_STATUSES)
                    ->where('time_from', '<', $to)
                    ->where('time_to', '>', $from)
                    ->when($ignoreRequestId, fn ($q) => $q->where('id', '!=', $ignoreRequestId));
            })
            ->pluck('employee_name')
            ->unique()
            ->values();
    }
}
