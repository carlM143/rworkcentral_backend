<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Authenticate user and issue Sanctum token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->safe()->only([
            'email',
            'password',
        ]);

        $user = User::query()
            ->where('email', $credentials['email'])
            ->first();

        // Validate credentials.
        if (
            !$user ||
            !Hash::check(
                $credentials['password'],
                $user->password
            )
        ) {
            return response()->json([
                'success' => false,
                'message' => 'The provided credentials are incorrect.',
            ], 401);
        }

        // Format user and permissions before creating token.
        $formattedUser = $this->formatUser($user);

        // Generate Sanctum token.
        $tokenName = $request->validated('device_name')
            ?? 'api-token';

        $token = $user
            ->createToken($tokenName)
            ->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'data' => [
                'user' => $formattedUser,
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ], 200);
    }

    /**
     * Get the currently authenticated user.
     * Always return latest role and permissions.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'message' => 'Authenticated user retrieved.',
            'data' => [
                'user' => $this->formatUser($user),
            ],
        ], 200);
    }

    /**
     * Revoke current Sanctum access token.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $token = $user->currentAccessToken();

        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Logout successful.',
        ], 200);
    }

    /**
     * Return safe user data with role and privileges.
     */
    private function formatUser(User $user): array
    {
        // Load fresh role and privilege relationships.
        $user->load([
            'role.privileges',
            'employee',
        ]);

        $role = $user->role;

        $privileges = [];

        if ($role) {
            $privileges = $role->privileges
                ->map(function ($privilege) {
                    return [
                        'id' => $privilege->id,
                        'module' => $privilege->module,
                        'name' => $privilege->name,
                        'description' => $privilege->description,
                    ];
                })
                ->values()
                ->all();
        }

        return [
            'id' => $user->id,

            'employee_id' => $user->employee?->id,

            'name' => $user->name,

            'email' => $user->email,

            'role' => $role
                ? [
                    'id' => $role->id,
                    'name' => $role->name,
                ]
                : null,

            'privileges' => $privileges,
        ];
    }
}
