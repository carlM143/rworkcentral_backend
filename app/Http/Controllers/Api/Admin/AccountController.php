<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * The signed-in user's own account (admin side).
 *
 *   GET    /admin/account              profile, role, linked employee, sessions
 *   PUT    /admin/account              update name and email
 *   PUT    /admin/account/password     change password
 *   DELETE /admin/account/sessions     sign out on every other device
 */
class AccountController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return $this->respond($request->user(), 'Account retrieved.');
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
        ], [
            'email.unique' => 'This email is already used by another account.',
        ]);

        $user->update([
            'name' => trim($validated['name']),
            'email' => mb_strtolower(trim($validated['email'])),
        ]);

        return $this->respond($user, 'Account details saved.');
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => [
                'required', 'confirmed', 'different:current_password',
                Password::min(8)->letters()->numbers(),
            ],
            'sign_out_other_devices' => ['nullable', 'boolean'],
        ], [
            'password.different' => 'The new password must be different from the current one.',
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'The current password is incorrect.',
            ]);
        }

        $user->update(['password' => Hash::make($validated['password'])]);

        if ($request->boolean('sign_out_other_devices')) {
            $this->revokeOtherTokens($request);
        }

        return $this->respond($user, 'Password changed.');
    }

    public function destroySessions(Request $request): JsonResponse
    {
        $revoked = $this->revokeOtherTokens($request);

        return $this->respond(
            $request->user(),
            $revoked ? "Signed out on {$revoked} other device(s)." : 'No other devices were signed in.'
        );
    }

    // =====================================================
    // HELPERS
    // =====================================================

    /**
     * Delete every Sanctum token of the user except the one in use.
     */
    private function revokeOtherTokens(Request $request): int
    {
        $user = $request->user();
        $current = $user->currentAccessToken();

        return $user->tokens()
            ->when($current && isset($current->id), fn ($q) => $q->where('id', '!=', $current->id))
            ->delete();
    }

    private function respond(User $user, string $message): JsonResponse
    {
        $user->loadMissing(['role', 'employee']);
        $employee = $user->employee;

        $employment = $employee
            ? DB::table('employments')
                ->leftJoin('positions', 'positions.id', '=', 'employments.position_id')
                ->leftJoin('departments', 'departments.id', '=', 'employments.department_id')
                ->where('employments.employee_id', $employee->id)
                ->orderByDesc('employments.id')
                ->first(['positions.position', 'departments.department_name', 'employments.date_hired'])
            : null;

        $current = $user->currentAccessToken();

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->name,
                'member_since' => $user->created_at?->toDateString(),
                'updated_at' => $user->updated_at?->toIso8601String(),
                'employee' => $employee ? [
                    'id' => $employee->id,
                    'emp_id' => $employee->emp_id,
                    'name' => trim(implode(' ', array_filter([$employee->firstname, $employee->middlename, $employee->lastname]))),
                    'position' => $employment?->position,
                    'department' => $employment?->department_name,
                    'date_hired' => $employment?->date_hired,
                ] : null,
                'sessions' => [
                    'active' => $user->tokens()->count(),
                    'current_last_used_at' => $current?->last_used_at?->toIso8601String(),
                ],
            ],
        ]);
    }
}