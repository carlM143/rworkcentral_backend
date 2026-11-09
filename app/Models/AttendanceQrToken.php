<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceQrToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'attendance_qr_session_id',
        'token_hash',
        'issued_at',
        'expires_at',
        'revoked_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(
            AttendanceQrSession::class,
            'attendance_qr_session_id'
        );
    }

    public function scans(): HasMany
    {
        return $this->hasMany(AttendanceScan::class);
    }
}