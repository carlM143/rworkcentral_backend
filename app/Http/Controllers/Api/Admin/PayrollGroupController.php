<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeePayrollGroupAssignment;
use App\Models\Location;
use App\Models\PayrollGroup;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PayrollGroupController extends Controller
{
    private const FREQUENCIES = [
        'weekly',
        'bi_weekly',
        'semi_monthly',
        'monthly',
    ];

    // GET /api/admin/payroll-groups
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', Rule::in(['0', '1'])],
        ]);

        $today = now('Asia/Manila')->toDateString();

        $query = PayrollGroup::query()
            ->withCount([
                'payrolls',
                'assignments as current_employee_count' => fn ($q) => $q
                    ->whereDate('effective_from', '<=', $today)
                    ->where(fn ($q) => $q->whereNull('effective_to')
                        ->orWhereDate('effective_to', '>=', $today)),
            ])
            ->orderBy('group_name');

        if (!empty($filters['search'])) {
            $query->where(
                'group_name',
                'like',
                '%' . trim($filters['search']) . '%'
            );
        }

        if (
            array_key_exists('is_active', $filters) &&
            $filters['is_active'] !== null
        ) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Payroll groups retrieved successfully.',
            'data' => $query->get(),
            'stats' => [
                'total' => PayrollGroup::count(),
                'active' => PayrollGroup::where('is_active', true)->count(),
                'inactive' => PayrollGroup::where('is_active', false)->count(),
            ],
        ]);
    }

    // GET /api/admin/payroll-groups/{payrollGroup}
    public function show(PayrollGroup $payrollGroup): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $payrollGroup->loadCount([
                'payrolls',
                'assignments',
            ]),
        ]);
    }

    // POST /api/admin/payroll-groups
    public function store(Request $request): JsonResponse
    {
        $request->merge([
            'group_name' => trim((string) $request->input('group_name')),
        ]);

        $data = $request->validate([
            'group_name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('payroll_groups', 'group_name'),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'frequency' => ['required', Rule::in(self::FREQUENCIES)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $group = PayrollGroup::create([
            'group_name' => $data['group_name'],
            'description' => $data['description'] ?? null,
            'frequency' => $data['frequency'],
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payroll group created successfully.',
            'data' => $group,
        ], 201);
    }

    // PUT/PATCH /api/admin/payroll-groups/{payrollGroup}
    public function update(
        Request $request,
        PayrollGroup $payrollGroup
    ): JsonResponse {
        if ($request->exists('group_name')) {
            $request->merge([
                'group_name' => trim((string) $request->input('group_name')),
            ]);
        }

        $data = $request->validate([
            'group_name' => [
                'sometimes',
                'required',
                'string',
                'max:150',
                Rule::unique('payroll_groups', 'group_name')
                    ->ignore($payrollGroup->id),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'frequency' => [
                'sometimes',
                'required',
                Rule::in(self::FREQUENCIES),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (
            isset($data['frequency']) &&
            $data['frequency'] !== $payrollGroup->frequency &&
            $payrollGroup->payrolls()->exists()
        ) {
            throw ValidationException::withMessages([
                'frequency' => [
                    'This group already has payroll records. Create a new group rather than changing its frequency.',
                ],
            ]);
        }

        $payrollGroup->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Payroll group updated successfully.',
            'data' => $payrollGroup->fresh(),
        ]);
    }

    // DELETE /api/admin/payroll-groups/{payrollGroup}
    public function destroy(PayrollGroup $payrollGroup): JsonResponse
    {
        if (
            $payrollGroup->assignments()->exists() ||
            $payrollGroup->payrolls()->exists()
        ) {
            throw ValidationException::withMessages([
                'group' => [
                    'This group has assignment or payroll history. Deactivate it instead of deleting it.',
                ],
            ]);
        }

        $payrollGroup->delete();

        return response()->json([
            'success' => true,
            'message' => 'Payroll group deleted successfully.',
        ]);
    }

    // GET /api/admin/payroll-groups/employee-filters
    public function employeeFilters(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'departments' => Department::query()
                    ->orderBy('id')
                    ->get(),
                'locations' => Location::query()
                    ->where('status', true)
                    ->orderBy('location_name')
                    ->get(['id', 'location_name', 'city', 'province']),
            ],
        ]);
    }

    // GET /api/admin/payroll-groups/employees
    public function employees(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'active_only' => ['nullable', Rule::in(['0', '1'])],
            'all' => ['nullable', Rule::in(['0', '1'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $date = $data['date'] ?? now('Asia/Manila')->toDateString();

        $query = Employee::query()
            ->select(
                'id',
                'emp_id',
                'firstname',
                'lastname',
                'department_id',
                'status'
            )
            ->with([
                'department',
                'latestEmployment.location:id,location_name',
                'payrollGroupAssignments' => fn ($q) => $q
                    ->whereDate('effective_from', '<=', $date)
                    ->where(fn ($q) => $q->whereNull('effective_to')
                        ->orWhereDate('effective_to', '>=', $date))
                    ->with('payrollGroup:id,group_name,frequency'),
            ]);

        if (($data['active_only'] ?? '1') === '1') {
            $query->where('status', true);
        }

        if (!empty($data['department_id'])) {
            $query->where('department_id', $data['department_id']);
        }

        if (!empty($data['location_id'])) {
            $query->whereHas(
                'latestEmployment',
                fn ($q) => $q->where('location_id', $data['location_id'])
            );
        }

        if (!empty($data['search'])) {
            $keyword = trim($data['search']);

            $query->where(fn ($q) => $q
                ->where('emp_id', 'like', "%{$keyword}%")
                ->orWhere('firstname', 'like', "%{$keyword}%")
                ->orWhere('lastname', 'like', "%{$keyword}%"));
        }

        $query->orderBy('lastname')->orderBy('firstname');

        if (($data['all'] ?? '0') === '1') {
            return response()->json([
                'success' => true,
                'data' => $query->limit(1000)->get(),
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $query->paginate($data['per_page'] ?? 25),
        ]);
    }

    // GET /api/admin/payroll-groups/{payrollGroup}/assignments
    public function assignments(
        Request $request,
        PayrollGroup $payrollGroup
    ): JsonResponse {
        $data = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $payrollGroup
                ->assignments()
                ->with([
                    'employee:id,emp_id,firstname,lastname',
                    'assignedBy:id,name',
                ])
                ->orderByDesc('effective_from')
                ->paginate($data['per_page'] ?? 15),
        ]);
    }

    // POST /api/admin/payroll-groups/{payrollGroup}/assignments
    public function assignEmployee(
        Request $request,
        PayrollGroup $payrollGroup
    ): JsonResponse {
        $data = $request->validate([
            'employee_id' => [
                'required',
                'integer',
                'exists:employees,id',
            ],
            'effective_from' => [
                'required',
                'date_format:Y-m-d',
            ],
        ]);

        $assignment = DB::transaction(function () use (
            $request,
            $payrollGroup,
            $data
        ) {
            $employee = Employee::query()
                ->lockForUpdate()
                ->findOrFail($data['employee_id']);

            $group = PayrollGroup::query()
                ->lockForUpdate()
                ->findOrFail($payrollGroup->id);

            if (!$employee->status || !$group->is_active) {
                throw ValidationException::withMessages([
                    'employee_id' => [
                        'Both the employee and payroll group must be active.',
                    ],
                ]);
            }

            $start = $data['effective_from'];

            $history = EmployeePayrollGroupAssignment::query()
                ->where('employee_id', $employee->id)
                ->orderBy('effective_from')
                ->lockForUpdate()
                ->get();

            if ($history->contains(
                fn ($row) => $row->effective_from->format('Y-m-d') > $start
            )) {
                throw ValidationException::withMessages([
                    'effective_from' => [
                        'A later group assignment already exists. Review the schedule first.',
                    ],
                ]);
            }

            $overlaps = $history->filter(function ($row) use ($start) {
                $from = $row->effective_from->format('Y-m-d');
                $to = $row->effective_to?->format('Y-m-d');

                return $from <= $start && ($to === null || $to >= $start);
            });

            if ($overlaps->count() > 1) {
                throw ValidationException::withMessages([
                    'employee_id' => [
                        'Overlapping payroll group history exists. Resolve it before proceeding.',
                    ],
                ]);
            }

            $previous = $overlaps->first();

            if ($previous) {
                if (
                    $previous->effective_from->format('Y-m-d') === $start ||
                    (int) $previous->payroll_group_id === (int) $group->id
                ) {
                    throw ValidationException::withMessages([
                        'effective_from' => [
                            'Employee already has a group assignment on this effective date.',
                        ],
                    ]);
                }

                $previous->update([
                    'effective_to' => Carbon::parse($start)
                        ->subDay()
                        ->toDateString(),
                ]);
            }

            return EmployeePayrollGroupAssignment::create([
                'employee_id' => $employee->id,
                'payroll_group_id' => $group->id,
                'effective_from' => $start,
                'effective_to' => null,
                'assigned_by' => $request->user()?->getAuthIdentifier(),
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Employee assigned to payroll group successfully.',
            'data' => $assignment->load([
                'employee',
                'payrollGroup',
                'assignedBy',
            ]),
        ], 201);
    }

    // POST /api/admin/payroll-groups/{payrollGroup}/assignments/bulk
    public function bulkAssign(
        Request $request,
        PayrollGroup $payrollGroup
    ): JsonResponse {
        $data = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'employee_ids.*' => ['required', 'integer', 'distinct'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
        ]);

        $ids = collect($data['employee_ids'])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values();

        $start = $data['effective_from'];
        $userId = $request->user()?->getAuthIdentifier();

        $result = DB::transaction(function () use (
            $ids,
            $payrollGroup,
            $start,
            $userId
        ) {
            $employees = Employee::query()
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'emp_id', 'firstname', 'lastname', 'status'])
                ->keyBy('id');

            $group = PayrollGroup::query()
                ->lockForUpdate()
                ->findOrFail($payrollGroup->id);

            if (!$group->is_active) {
                throw ValidationException::withMessages([
                    'payroll_group' => [
                        'This payroll group is inactive. Activate it before assigning employees.',
                    ],
                ]);
            }

            $historyByEmployee = EmployeePayrollGroupAssignment::query()
                ->whereIn('employee_id', $ids)
                ->orderBy('effective_from')
                ->lockForUpdate()
                ->get()
                ->groupBy('employee_id');

            $assigned = [];
            $skipped = [];

            foreach ($ids as $id) {
                $employee = $employees->get($id);

                if (!$employee) {
                    $skipped[] = [
                        'employee_id' => $id,
                        'name' => null,
                        'reason' => 'Employee not found.',
                    ];
                    continue;
                }

                $label = trim("{$employee->emp_id} — {$employee->firstname} {$employee->lastname}");

                $skip = function (string $reason) use (&$skipped, $id, $label) {
                    $skipped[] = [
                        'employee_id' => $id,
                        'name' => $label,
                        'reason' => $reason,
                    ];
                };

                if (!$employee->status) {
                    $skip('Employee is inactive.');
                    continue;
                }

                $history = $historyByEmployee->get($id, collect());

                if ($history->contains(
                    fn ($row) => $row->effective_from->format('Y-m-d') > $start
                )) {
                    $skip('A later group assignment is already scheduled.');
                    continue;
                }

                $overlaps = $history->filter(function ($row) use ($start) {
                    $from = $row->effective_from->format('Y-m-d');
                    $to = $row->effective_to?->format('Y-m-d');

                    return $from <= $start && ($to === null || $to >= $start);
                });

                if ($overlaps->count() > 1) {
                    $skip('Overlapping payroll group history. Resolve it first.');
                    continue;
                }

                $previous = $overlaps->first();

                if ($previous) {
                    if ((int) $previous->payroll_group_id === (int) $group->id) {
                        $skip('Already in this payroll group.');
                        continue;
                    }

                    if ($previous->effective_from->format('Y-m-d') === $start) {
                        $skip('Already has a group assignment starting on this date.');
                        continue;
                    }

                    $previous->update([
                        'effective_to' => Carbon::parse($start)
                            ->subDay()
                            ->toDateString(),
                    ]);
                }

                EmployeePayrollGroupAssignment::create([
                    'employee_id' => $id,
                    'payroll_group_id' => $group->id,
                    'effective_from' => $start,
                    'effective_to' => null,
                    'assigned_by' => $userId,
                ]);

                $assigned[] = [
                    'employee_id' => $id,
                    'name' => $label,
                    'moved_from_group_id' => $previous?->payroll_group_id,
                ];
            }

            return [
                'assigned' => $assigned,
                'skipped' => $skipped,
            ];
        });

        $assignedCount = count($result['assigned']);
        $skippedCount = count($result['skipped']);

        $message = $assignedCount === 0
            ? 'No employees were assigned.'
            : "{$assignedCount} employee(s) assigned to {$payrollGroup->group_name}.";

        if ($skippedCount > 0) {
            $message .= " {$skippedCount} skipped.";
        }

        return response()->json([
            'success' => $assignedCount > 0,
            'message' => $message,
            'data' => $result,
        ], $assignedCount > 0 ? 201 : 200);
    }

    // PATCH /api/admin/payroll-groups/{payrollGroup}/assignments/{assignment}/end
    public function endAssignment(
        Request $request,
        PayrollGroup $payrollGroup,
        EmployeePayrollGroupAssignment $assignment
    ): JsonResponse {
        $data = $request->validate([
            'effective_to' => ['required', 'date_format:Y-m-d'],
        ]);

        $result = DB::transaction(function () use (
            $payrollGroup,
            $assignment,
            $data
        ) {
            Employee::query()
                ->lockForUpdate()
                ->findOrFail($assignment->employee_id);

            $item = EmployeePayrollGroupAssignment::query()
                ->where('payroll_group_id', $payrollGroup->id)
                ->lockForUpdate()
                ->findOrFail($assignment->id);

            if ($item->effective_to !== null) {
                throw ValidationException::withMessages([
                    'effective_to' => [
                        'This assignment has already ended.',
                    ],
                ]);
            }

            $from = $item->effective_from->format('Y-m-d');
            $to = $data['effective_to'];

            if ($to < $from) {
                throw ValidationException::withMessages([
                    'effective_to' => [
                        'End date must be on or after the assignment start date.',
                    ],
                ]);
            }

            $otherOverlap = EmployeePayrollGroupAssignment::query()
                ->where('employee_id', $item->employee_id)
                ->where('id', '!=', $item->id)
                ->whereDate('effective_from', '<=', $to)
                ->where(fn ($q) => $q->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $from))
                ->exists();

            if ($otherOverlap) {
                throw ValidationException::withMessages([
                    'effective_to' => [
                        'This date overlaps another payroll group assignment.',
                    ],
                ]);
            }

            $item->update(['effective_to' => $to]);

            return $item;
        });

        return response()->json([
            'success' => true,
            'message' => 'Payroll group assignment ended. History was preserved.',
            'data' => $result->fresh([
                'employee',
                'payrollGroup',
            ]),
        ]);
    }

    // UNDO A MISTAKEN ASSIGNMENT: deletes it and reopens the employee's previous group
    // DELETE /api/admin/payroll-groups/{payrollGroup}/assignments/{assignment}
    public function undoAssignment(
        PayrollGroup $payrollGroup,
        EmployeePayrollGroupAssignment $assignment
    ): JsonResponse {
        $restored = DB::transaction(function () use (
            $payrollGroup,
            $assignment
        ) {
            Employee::query()
                ->lockForUpdate()
                ->findOrFail($assignment->employee_id);

            $item = EmployeePayrollGroupAssignment::query()
                ->where('payroll_group_id', $payrollGroup->id)
                ->lockForUpdate()
                ->findOrFail($assignment->id);

            $from = $item->effective_from->format('Y-m-d');
            $to = $item->effective_to?->format('Y-m-d');

            // Only the latest assignment can be undone, so history stays consistent
            $hasLater = EmployeePayrollGroupAssignment::query()
                ->where('employee_id', $item->employee_id)
                ->where('id', '!=', $item->id)
                ->whereDate('effective_from', '>', $from)
                ->exists();

            if ($hasLater) {
                throw ValidationException::withMessages([
                    'assignment' => [
                        'A later group assignment exists for this employee. Undo that one first.',
                    ],
                ]);
            }

            // Payroll already generated under this assignment: keep it, end it instead
            $usedInPayroll = DB::table('payrolls')
                ->join('payroll_periods', 'payroll_periods.id', '=', 'payrolls.payroll_period_id')
                ->where('payrolls.employee_id', $item->employee_id)
                ->where('payrolls.payroll_group_id', $item->payroll_group_id)
                ->whereDate('payroll_periods.cutoff_end', '>=', $from)
                ->when($to, fn ($q) => $q->whereDate('payroll_periods.cutoff_start', '<=', $to))
                ->exists();

            if ($usedInPayroll) {
                throw ValidationException::withMessages([
                    'assignment' => [
                        'Payroll has already been generated under this assignment. End the assignment instead.',
                    ],
                ]);
            }

            // The assignment that was closed when this one was created
            $previous = EmployeePayrollGroupAssignment::query()
                ->where('employee_id', $item->employee_id)
                ->where('id', '!=', $item->id)
                ->whereDate(
                    'effective_to',
                    Carbon::parse($from)->subDay()->toDateString()
                )
                ->lockForUpdate()
                ->first();

            $item->delete();

            $previous?->update(['effective_to' => $to]);

            return $previous;
        });

        return response()->json([
            'success' => true,
            'message' => $restored
                ? 'Assignment undone. The employee was returned to their previous payroll group.'
                : 'Assignment undone. The employee has no payroll group assignment now.',
            'data' => [
                'restored_assignment' => $restored?->fresh([
                    'employee',
                    'payrollGroup',
                ]),
            ],
        ]);
    }
}