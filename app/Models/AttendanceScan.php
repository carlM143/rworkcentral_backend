<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceScan extends Model
{
    use HasFactory;

    public const ACTION_TIME_IN = 'time_in';
    public const ACTION_TIME_OUT = 'time_out';

    public const RESULT_ACCEPTED = 'accepted';
    public const RESULT_REJECTED = 'rejected';

    protected $fillable = [
        'employee_id',
        'attendance_qr_session_id',
        'attendance_qr_token_id',
        'time_log_id',
        'action',
        'result',
        'scanned_at',
        'rejection_reason',
        'device_info',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'scanned_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(
            AttendanceQrSession::class,
            'attendance_qr_session_id'
        );
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(
            AttendanceQrToken::class,
            'attendance_qr_token_id'
        );
    }

    public function timeLog(): BelongsTo
    {
        return $this->belongsTo(TimeLog::class);
    }
}