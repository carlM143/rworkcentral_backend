<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceQrSession;
use App\Models\AttendanceQrToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GenerateQrCodeController extends Controller
{
    /**
     * How long each rotating QR token stays valid (seconds).
     * The frontend should call refresh() slightly before this elapses.
     */
    private const TOKEN_TTL_SECONDS = 60;

    /**
     * Create a new attendance QR session and issue its first token.
     *
     * POST /api/admin/attendance-qr/sessions
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'      => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at'   => ['required', 'date', 'after:starts_at'],
        ]);

        [$session, $plainToken, $token] = DB::transaction(function () use ($request, $data) {
            $session = AttendanceQrSession::create([
                'created_by' => $request->user()->id,
                'name'       => $data['name'],
                'starts_at'  => $data['starts_at'],
                'ends_at'    => $data['ends_at'],
                'status'     => 'active',
            ]);

            [$plainToken, $token] = $this->issueToken($session);

            return [$session, $plainToken, $token];
        });

        return response()->json([
            'message' => 'QR session created.',
            'data'    => $this->payload($session, $plainToken, $token),
        ], 201);
    }

    /**
     * Rotate the token: revoke active tokens and issue a fresh one.
     * The admin screen calls this on a timer to keep the QR code changing.
     *
     * POST /api/admin/attendance-qr/sessions/{session}/refresh
     */
    public function refresh(AttendanceQrSession $session): JsonResponse
    {
        $this->ensureSessionUsable($session);

        [$plainToken, $token] = DB::transaction(function () use ($session) {
            $session->tokens()
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            return $this->issueToken($session);
        });

        return response()->json([
            'data' => $this->payload($session, $plainToken, $token),
        ]);
    }

    /**
     * Revoke the session and all of its tokens.
     *
     * POST /api/admin/attendance-qr/sessions/{session}/revoke
     */
    public function revoke(AttendanceQrSession $session): JsonResponse
    {
        DB::transaction(function () use ($session) {
            $session->tokens()
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $session->update([
                'status'     => 'revoked',
                'revoked_at' => now(),
            ]);
        });

        return response()->json(['message' => 'QR session revoked.']);
    }

    /**
     * Create a token row (stores only the hash) and return the plain value.
     *
     * @return array{0: string, 1: AttendanceQrToken}
     */
    private function issueToken(AttendanceQrSession $session): array
    {
        $plainToken = Str::random(64);

        $token = $session->tokens()->create([
            'token_hash' => hash('sha256', $plainToken),
            'issued_at'  => now(),
            'expires_at' => now()->addSeconds(self::TOKEN_TTL_SECONDS),
        ]);

        return [$plainToken, $token];
    }

    /**
     * The string below is what gets encoded into the QR image.
     * The plain token is only ever returned here; the DB keeps the hash.
     */
    private function payload(AttendanceQrSession $session, string $plainToken, AttendanceQrToken $token): array
    {
        return [
            'session_id' => $session->id,
            'name'       => $session->name,
            'starts_at'  => $session->starts_at,
            'ends_at'    => $session->ends_at,
            'expires_at' => $token->expires_at,
            'ttl'        => self::TOKEN_TTL_SECONDS,
            'qr_payload' => json_encode([
                's' => $session->id,
                't' => $plainToken,
            ]),
        ];
    }

    private function ensureSessionUsable(AttendanceQrSession $session): void
    {
        if ($session->status !== 'active' || $session->revoked_at !== null) {
            throw ValidationException::withMessages([
                'session' => 'This QR session has been revoked.',
            ]);
        }

        if (now()->lt($session->starts_at) || now()->gt($session->ends_at)) {
            throw ValidationException::withMessages([
                'session' => 'This QR session is outside its active time window.',
            ]);
        }
    }
}