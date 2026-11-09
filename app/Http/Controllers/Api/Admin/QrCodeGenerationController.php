<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceQrSession;
use App\Models\AttendanceQrToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QrCodeGenerationController extends Controller
{
    // How long one QR token stays valid (seconds)
    private const TOKEN_TTL_SECONDS = 60;

    // GENERATE ATTENDANCE QR CODE (time in / time out)
    // POST /api/admin/attendance-qr/generate
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'      => ['nullable', 'string', 'max:255'],
            'starts_at' => ['nullable', 'date'],
            'ends_at'   => ['nullable', 'date', 'after:starts_at'],
        ]);

        $startsAt = isset($data['starts_at']) ? now()->parse($data['starts_at']) : now();
        $endsAt   = isset($data['ends_at']) ? now()->parse($data['ends_at']) : $startsAt->copy()->endOfDay();

        [$session, $plainToken, $token] = DB::transaction(function () use ($request, $data, $startsAt, $endsAt) {
            $session = AttendanceQrSession::create([
                'created_by' => $request->user()->id,
                'name'       => $data['name'] ?? 'Attendance ' . $startsAt->toDateString(),
                'starts_at'  => $startsAt,
                'ends_at'    => $endsAt,
                'status'     => 'active',
            ]);

            $plainToken = Str::random(64);

            $token = $session->tokens()->create([
                'token_hash' => hash('sha256', $plainToken),
                'issued_at'  => now(),
                'expires_at' => now()->addSeconds(self::TOKEN_TTL_SECONDS),
            ]);

            return [$session, $plainToken, $token];
        });

        return response()->json([
            'success' => true,
            'message' => 'Attendance QR code generated successfully.',
            'data'    => $this->payload($session, $plainToken, $token),
        ], 201);
    }

    // What the frontend encodes into the QR image. Only the hash is stored in the DB.
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
}
