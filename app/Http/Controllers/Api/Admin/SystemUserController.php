<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SystemUserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::query()
            ->with(['role', 'employee.department', 'employee.contactInformation'])
            ->get()
            ->map(fn (User $user): array => $this->formatUser($user));

        $pendingEmployees = Employee::query()
            ->whereNull('user_id')
            ->with(['department', 'contactInformation'])
            ->get()
            ->map(fn (Employee $employee): array => [
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
            ]);

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

        $roles = Role::query()
            ->select('id', 'name')
            ->when(
                $request->user()->role?->name !== 'Administrator',
                fn ($query) => $query->where('name', '!=', 'Administrator')
            )
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'System user edit data retrieved successfully.',
            'data' => [
                'user' => $this->formatUser(
                    $user->load(['role', 'employee.department', 'employee.contactInformation'])
                ),
                'available_roles' => $roles,
            ],
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

        abort_if(
            $request->user()->role?->name !== 'Administrator'
                && $role->name === 'Administrator',
            403,
            'Only an Administrator can assign the Administrator role.'
        );

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
