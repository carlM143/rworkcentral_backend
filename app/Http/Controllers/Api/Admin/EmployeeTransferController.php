<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDepartmentTransfer;
use App\Models\Employment;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeTransferController extends Controller
{
    private const ACTIVE = ['pending', 'approved', 'scheduled'];
    private const STATUSES = [
        'pending', 'approved', 'scheduled',
        'applied', 'rejected', 'cancelled',
    ];

    private const RELATIONS = [
        'employee',
        'fromDepartment',
        'toDepartment',
        'creator',
        'reviewer',
        'applier',
    ];

    // GET /api/admin/employee-department-transfers
    public function index(Request $request): JsonResponse
    {
        $this->authorizeHr($request);

        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'employee_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = EmployeeDepartmentTransfer::query()
            ->with(self::RELATIONS)
            ->latest('id');

        if (!empty($data['search'])) {
            $search = trim($data['search']);

            $query->whereHas('employee', function ($q) use ($search) {
                $q->where('firstname', 'like', "%{$search}%")
                    ->orWhere('lastname', 'like', "%{$search}%")
                    ->orWhere('emp_id', 'like', "%{$search}%")
                    ->orWhereRaw(
                        "CONCAT_WS(' ', firstname, lastname) LIKE ?",
                        ["%{$search}%"]
                    );
            });
        }

        if (!empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        if (!empty($data['employee_id'])) {
            $query->where('employee_id', $data['employee_id']);
        }

        $stats = [
            'total' => EmployeeDepartmentTransfer::count(),
            'pending' => EmployeeDepartmentTransfer::where('status', 'pending')->count(),
            'approved' => EmployeeDepartmentTransfer::where('status', 'approved')->count(),
            'scheduled' => EmployeeDepartmentTransfer::where('status', 'scheduled')->count(),
            'applied' => EmployeeDepartmentTransfer::where('status', 'applied')->count(),
            'rejected' => EmployeeDepartmentTransfer::where('status', 'rejected')->count(),
            'cancelled' => EmployeeDepartmentTransfer::where('status', 'cancelled')->count(),
        ];

        return response()->json([
            'success' => true,
            'message' => 'Employee transfers retrieved successfully.',
            'data' => $query->paginate($data['per_page'] ?? 15),
            'stats' => $stats,
        ]);
    }

    // GET /api/admin/employee-department-transfers/{transfer}
    public function show(
        Request $request,
        EmployeeDepartmentTransfer $transfer
    ): JsonResponse {
        $this->authorizeHr($request);

        return response()->json([
            'success' => true,
            'data' => $transfer->load(self::RELATIONS),
        ]);
    }

    // POST /api/admin/employee-department-transfers
    public function store(Request $request): JsonResponse
    {
        $this->authorizeHr($request);

        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'to_department_id' => ['required', 'integer', 'exists:departments,id'],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'mode' => ['nullable', Rule::in(['direct', 'request'])],
        ]);

        $today = Carbon::today('Asia/Manila')->toDateString();
        $mode = $data['mode'] ?? 'request';

        $transfer = DB::transaction(function () use ($request, $data, $today, $mode) {
            $employee = Employee::query()
                ->lockForUpdate()
                ->findOrFail($data['employee_id']);

            $employment = $this->currentEmployment($employee->id);

            $fromId = $this->currentDepartmentId($employee, $employment);
            $toId = (int) $data['to_department_id'];

            $this->validateDepartments($fromId, $toId);
            $this->ensureNoActiveTransfer($employee->id);

            $applyNow = $mode === 'direct' && $data['effective_date'] <= $today;

            $status = $mode === 'request'
                ? 'pending'
                : ($applyNow ? 'applied' : 'scheduled');

            $transfer = EmployeeDepartmentTransfer::create([
                'employee_id' => $employee->id,
                'from_department_id' => $fromId,
                'to_department_id' => $toId,
                'effective_date' => $data['effective_date'],
                'reason' => trim($data['reason'] ?? '') ?: null,
                'status' => $status,
                'created_by' => $request->user()->getAuthIdentifier(),
                'applied_by' => $applyNow
                    ? $request->user()->getAuthIdentifier()
                    : null,
                'applied_at' => $applyNow ? now() : null,
            ]);

            // Direct transfers become effective immediately when due.
            if ($applyNow) {
                $employment->update(['department_id' => $toId]);
                $employee->update(['department_id' => $toId]);
            }

            return $transfer;
        });

        return response()->json([
            'success' => true,
            'message' => $transfer->status === 'applied'
                ? 'Employee transferred successfully.'
                : ($transfer->status === 'scheduled'
                    ? 'Department transfer scheduled successfully.'
                    : 'Department transfer request submitted successfully.'),
            'data' => $transfer->load(self::RELATIONS),
        ], 201);
    }

    // PUT/PATCH /api/admin/employee-department-transfers/{transfer}
    public function update(
        Request $request,
        EmployeeDepartmentTransfer $transfer
    ): JsonResponse {
        $this->authorizeHr($request);

        $data = $request->validate([
            'to_department_id' => ['required', 'integer', 'exists:departments,id'],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $updated = DB::transaction(function () use ($transfer, $data) {
            $employee = Employee::query()
                ->lockForUpdate()
                ->findOrFail($transfer->employee_id);

            $employment = $this->currentEmployment($employee->id);

            $item = EmployeeDepartmentTransfer::query()
                ->lockForUpdate()
                ->findOrFail($transfer->id);

            if (!in_array($item->status, ['pending', 'scheduled'], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Only pending or scheduled transfers can be edited.'],
                ]);
            }

            if ($this->currentDepartmentId($employee, $employment) !== (int) $item->from_department_id) {
                throw ValidationException::withMessages([
                    'employee_id' => ['Employee department has changed. Review this transfer first.'],
                ]);
            }

            $this->validateDepartments(
                (int) $item->from_department_id,
                (int) $data['to_department_id']
            );

            $item->update([
                'to_department_id' => $data['to_department_id'],
                'effective_date' => $data['effective_date'],
                'reason' => trim($data['reason'] ?? '') ?: null,
            ]);

            return $item;
        });

        return response()->json([
            'success' => true,
            'message' => 'Department transfer updated successfully.',
            'data' => $updated->fresh(self::RELATIONS),
        ]);
    }

    // DELETE /api/admin/employee-department-transfers/{transfer}
    public function destroy(
        Request $request,
        EmployeeDepartmentTransfer $transfer
    ): JsonResponse {
        $this->authorizeHr($request);

        DB::transaction(function () use ($transfer) {
            $item = EmployeeDepartmentTransfer::query()
                ->lockForUpdate()
                ->findOrFail($transfer->id);

            if (!in_array($item->status, ['pending', 'scheduled'], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Only pending or scheduled transfers can be deleted.'],
                ]);
            }

            $item->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'Department transfer deleted successfully.',
        ]);
    }

    // PATCH /api/admin/employee-department-transfers/{transfer}/approve
    public function approve(
        Request $request,
        EmployeeDepartmentTransfer $transfer
    ): JsonResponse {
        $this->authorizeHr($request);

        $data = $request->validate([
            'review_remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $item = DB::transaction(function () use ($request, $transfer, $data) {
            $item = EmployeeDepartmentTransfer::query()
                ->lockForUpdate()
                ->findOrFail($transfer->id);

            if ($item->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => ['Only pending transfers can be approved.'],
                ]);
            }

            if ((int) $item->created_by === (int) $request->user()->getAuthIdentifier()) {
                abort(403, 'You cannot approve your own transfer request.');
            }

            $item->update([
                'status' => 'approved',
                'reviewed_by' => $request->user()->getAuthIdentifier(),
                'reviewed_at' => now(),
                'review_remarks' => trim($data['review_remarks'] ?? '') ?: null,
            ]);

            return $item;
        });

        return response()->json([
            'success' => true,
            'message' => 'Transfer approved. Waiting to be applied.',
            'data' => $item->fresh(self::RELATIONS),
        ]);
    }

    // PATCH /api/admin/employee-department-transfers/{transfer}/reject
    public function reject(
        Request $request,
        EmployeeDepartmentTransfer $transfer
    ): JsonResponse {
        $this->authorizeHr($request);

        $data = $request->validate([
            'review_remarks' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $item = DB::transaction(function () use ($request, $transfer, $data) {
            $item = EmployeeDepartmentTransfer::query()
                ->lockForUpdate()
                ->findOrFail($transfer->id);

            if ($item->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => ['Only pending transfers can be rejected.'],
                ]);
            }

            if ((int) $item->created_by === (int) $request->user()->getAuthIdentifier()) {
                abort(403, 'You cannot reject your own transfer request.');
            }

            $item->update([
                'status' => 'rejected',
                'reviewed_by' => $request->user()->getAuthIdentifier(),
                'reviewed_at' => now(),
                'review_remarks' => trim($data['review_remarks']),
            ]);

            return $item;
        });

        return response()->json([
            'success' => true,
            'message' => 'Department transfer rejected.',
            'data' => $item->fresh(self::RELATIONS),
        ]);
    }

    // POST /api/admin/employee-department-transfers/{transfer}/apply
    public function apply(
        Request $request,
        EmployeeDepartmentTransfer $transfer
    ): JsonResponse {
        $this->authorizeHr($request);

        $item = DB::transaction(function () use ($request, $transfer) {
            $employee = Employee::query()
                ->lockForUpdate()
                ->findOrFail($transfer->employee_id);

            $employment = $this->currentEmployment($employee->id);

            $item = EmployeeDepartmentTransfer::query()
                ->lockForUpdate()
                ->findOrFail($transfer->id);

            if (!in_array($item->status, ['approved', 'scheduled'], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Only approved or scheduled transfers can be applied.'],
                ]);
            }

            $today = Carbon::today('Asia/Manila')->toDateString();
            $effectiveDate = $item->effective_date->format('Y-m-d');

            if ($effectiveDate > $today) {
                throw ValidationException::withMessages([
                    'effective_date' => ['This transfer cannot be applied before its effective date.'],
                ]);
            }

            $currentId = $this->currentDepartmentId($employee, $employment);

            if ($currentId !== (int) $item->from_department_id) {
                throw ValidationException::withMessages([
                    'employee_id' => ['Employee department has changed since this transfer was created.'],
                ]);
            }

            $this->validateDepartments(
                (int) $item->from_department_id,
                (int) $item->to_department_id
            );

            // Update the employee's active department together.
            $employment->update([
                'department_id' => $item->to_department_id,
            ]);

            $employee->update([
                'department_id' => $item->to_department_id,
            ]);

            $item->update([
                'status' => 'applied',
                'applied_by' => $request->user()->getAuthIdentifier(),
                'applied_at' => now(),
            ]);

            return $item;
        });

        return response()->json([
            'success' => true,
            'message' => 'Department transfer applied successfully.',
            'data' => $item->fresh(self::RELATIONS),
        ]);
    }

    // PATCH /api/admin/employee-department-transfers/{transfer}/cancel
    public function cancel(
        Request $request,
        EmployeeDepartmentTransfer $transfer
    ): JsonResponse {
        $this->authorizeHr($request);

        $item = DB::transaction(function () use ($transfer) {
            $item = EmployeeDepartmentTransfer::query()
                ->lockForUpdate()
                ->findOrFail($transfer->id);

            if (!in_array($item->status, self::ACTIVE, true)) {
                throw ValidationException::withMessages([
                    'status' => ['This transfer can no longer be cancelled.'],
                ]);
            }

            $item->update(['status' => 'cancelled']);

            return $item;
        });

        return response()->json([
            'success' => true,
            'message' => 'Department transfer cancelled successfully.',
            'data' => $item->fresh(self::RELATIONS),
        ]);
    }

    // Find the latest effective employment record.
    private function currentEmployment(int $employeeId): Employment
    {
        $employment = Employment::query()
            ->where('employee_id', $employeeId)
            ->whereDate('date_hired', '<=', Carbon::today('Asia/Manila')->toDateString())
            ->orderByDesc('date_hired')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if (!$employment) {
            throw ValidationException::withMessages([
                'employee_id' => ['Employee has no active employment record.'],
            ]);
        }

        return $employment;
    }

    // Determine current department from employment or employee.
    private function currentDepartmentId(
        Employee $employee,
        Employment $employment
    ): int {
        $id = (int) ($employment->department_id ?: $employee->department_id);

        if (!$id) {
            throw ValidationException::withMessages([
                'employee_id' => ['Employee has no assigned department.'],
            ]);
        }

        return $id;
    }

    // Prevent same-department and cross-company transfers.
    private function validateDepartments(int $fromId, int $toId): void
    {
        if ($fromId === $toId) {
            throw ValidationException::withMessages([
                'to_department_id' => ['Select a different destination department.'],
            ]);
        }

        $from = Department::findOrFail($fromId);
        $to = Department::findOrFail($toId);

        if (
            !$from->company_id ||
            !$to->company_id ||
            (int) $from->company_id !== (int) $to->company_id
        ) {
            throw ValidationException::withMessages([
                'to_department_id' => ['Both departments must belong to the same company.'],
            ]);
        }
    }

    // Allow only one unfinished transfer per employee.
    private function ensureNoActiveTransfer(int $employeeId): void
    {
        $exists = EmployeeDepartmentTransfer::query()
            ->where('employee_id', $employeeId)
            ->whereIn('status', self::ACTIVE)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'employee_id' => ['Employee already has a pending, approved, or scheduled transfer.'],
            ]);
        }
    }

    // Match existing RWorkCentral Admin/HR role configuration.
    private function authorizeHr(Request $request): void
    {
        $user = $request->user();

        abort_unless(
            $user && in_array((int) $user->role_id, [1, 2], true),
            403,
            'Only authorized Admin/HR users can manage employee transfers.'
        );
    }
}
