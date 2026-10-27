<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class SystemUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeRoleManagement($request);

        $users = User::query()
            ->with(['role', 'employee.department', 'employee.contactInformation'])
            ->get()
            ->map(fn (User $user): array => $this->formatUser($user));

        $pendingEmployees = Employee::query()
            ->whereNull('user_id')
            ->with(['department', 'contactInformation'])
            ->get()
            ->map(fn (Employee $employee): array => $this->formatPendingEmployee($employee));

        return response()->json([
            'success' => true,
            'message' => 'System users retrieved successfully.',
            'data' => $users
                ->concat($pendingEmployees)
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values(),
        ]);
    }

    public function edit(Request $request, User $user): JsonResponse
    {
        $this->authorizeRoleManagement($request);
        $this->ensureEmployeeAccount($user);

        return response()->json([
            'success' => true,
            'message' => 'System user edit data retrieved successfully.',
            'data' => [
                'user' => $this->formatUser(
                    $user->load(['role', 'employee.department', 'employee.contactInformation'])
                ),
                'available_roles' => $this->availableRoles($request),
            ],
        ]);
    }

    public function editEmployee(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeRoleManagement($request);

        $employee->load([
            'department',
            'contactInformation',
            'user.role',
            'user.employee.department',
            'user.employee.contactInformation',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Employee account edit data retrieved successfully.',
            'data' => [
                'user' => $employee->user
                    ? $this->formatUser($employee->user)
                    : $this->formatPendingEmployee($employee),
                'available_roles' => $this->availableRoles($request),
            ],
        ]);
    }

    public function setupAccount(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeRoleManagement($request);

        if ($employee->user_id !== null) {
            throw ValidationException::withMessages([
                'employee' => 'This employee already has a system user account.',
            ]);
        }

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $role = Role::findOrFail($validated['role_id']);
        $this->ensureRoleCanBeAssigned($request, $role);

        $user = DB::transaction(function () use ($employee, $validated, $role): User {
            $user = User::create([
                'name' => $this->employeeName($employee),
                'email' => $validated['email'],
                'password' => $validated['password'],
                'role_id' => $role->id,
            ]);

            $employee->update(['user_id' => $user->id]);
            $employee->contactInformation()->update([
                'email' => $validated['email'],
            ]);

            return $user;
        });

        return response()->json([
            'success' => true,
            'message' => 'Employee system account created successfully.',
            'data' => $this->formatUser(
                $user->load(['role', 'employee.department', 'employee.contactInformation'])
            ),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorizeRoleManagement($request);
        $this->ensureEmployeeAccount($user);

        $validated = $request->validate([
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'role_id' => ['sometimes', 'required', 'integer', Rule::exists('roles', 'id')],
            'password' => ['sometimes', 'nullable', 'confirmed', Password::min(8)],
        ]);

        if (isset($validated['role_id'])) {
            $this->ensureRoleCanBeAssigned(
                $request,
                Role::findOrFail($validated['role_id'])
            );
        }

        DB::transaction(function () use ($user, $validated): void {
            $updates = collect($validated)
                ->only(['email', 'role_id'])
                ->all();

            if (! empty($validated['password'])) {
                $updates['password'] = $validated['password'];
            }

            $updates['name'] = $this->employeeName($user->employee);
            $user->update($updates);

            if (isset($validated['email'])) {
                $user->employee->contactInformation()->update([
                    'email' => $validated['email'],
                ]);
            }

            if (! empty($validated['password'])) {
                $user->tokens()->delete();
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'System user updated successfully.',
            'data' => $this->formatUser(
                $user->fresh()->load(['role', 'employee.department', 'employee.contactInformation'])
            ),
        ]);
    }

    public function updateRole(Request $request, User $user): JsonResponse
    {
        $this->authorizeRoleManagement($request);
        $this->ensureEmployeeAccount($user);

        $validated = $request->validate([
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
        ]);

        $role = Role::findOrFail($validated['role_id']);
        $this->ensureRoleCanBeAssigned($request, $role);

        $user->update([
            'role_id' => $role->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'System user role updated successfully.',
            'data' => $this->formatUser(
                $user->fresh()->load(['role', 'employee.department', 'employee.contactInformation'])
            ),
        ]);
    }

    private function formatUser(User $user): array
    {
        $employee = $user->employee;

        return [
            'user_id' => $user->id,
            'employee_id' => $employee?->id,
            'name' => $employee ? $this->employeeName($employee) : $user->name,
            'email' => $user->email,
            'role' => $user->role ? [
                'id' => $user->role->id,
                'name' => $user->role->name,
            ] : null,
            'department' => $employee?->department ? [
                'id' => $employee->department->id,
                'name' => $employee->department->department_name,
                'code' => $employee->department->department_code,
            ] : null,
            'account_status' => 'active',
            'two_factor_status' => 'not_enabled',
            'last_login_at' => null,
            'can_setup_account' => false,
        ];
    }

    private function formatPendingEmployee(Employee $employee): array
    {
        return [
            'user_id' => null,
            'employee_id' => $employee->id,
            'name' => $this->employeeName($employee),
            'email' => $employee->contactInformation?->email,
            'role' => null,
            'department' => $employee->department ? [
                'id' => $employee->department->id,
                'name' => $employee->department->department_name,
                'code' => $employee->department->department_code,
            ] : null,
            'account_status' => 'pending_setup',
            'two_factor_status' => 'not_available',
            'last_login_at' => null,
            'can_setup_account' => true,
        ];
    }

    private function availableRoles(Request $request): Collection
    {
        return Role::query()
            ->select('id', 'name')
            ->when(
                $request->user()->role?->name !== 'Administrator',
                fn ($query) => $query->where('name', '!=', 'Administrator')
            )
            ->orderBy('name')
            ->get();
    }

    private function authorizeRoleManagement(Request $request): void
    {
        abort_unless(
            in_array($request->user()?->role?->name, ['Administrator', 'Human Resources'], true),
            403,
            'You are not authorized to manage user roles.'
        );
    }

    private function ensureEmployeeAccount(User $user): void
    {
        if (! $user->employee()->exists()) {
            throw ValidationException::withMessages([
                'user' => 'Only linked employee accounts can be assigned through this endpoint.',
            ]);
        }
    }

    private function ensureRoleCanBeAssigned(Request $request, Role $role): void
    {
        abort_if(
            $request->user()->role?->name !== 'Administrator'
                && $role->name === 'Administrator',
            403,
            'Only an Administrator can assign the Administrator role.'
        );
    }

    private function employeeName(Employee $employee): string
    {
        return collect([
            $employee->firstname,
            $employee->middlename,
            $employee->lastname,
            $employee->suffix,
        ])->filter()->implode(' ');
    }
}
