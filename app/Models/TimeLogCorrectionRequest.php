<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeLogCorrectionRequest extends Model
{
    protected $fillable = [
        'time_log_id',
        'employee_id',
        'requested_by',
        'reviewed_by',
        'correction_type',
        'priority',
        'status',
        'original_time_in',
        'original_time_out',
        'original_break_out',
        'original_break_in',
        'requested_time_in',
        'requested_time_out',
        'requested_break_out',
        'requested_break_in',
        'reason',
        'attachment_path',
        'review_remarks',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'original_time_in' => 'datetime',
            'original_time_out' => 'datetime',
            'original_break_out' => 'datetime',
            'original_break_in' => 'datetime',
            'requested_time_in' => 'datetime',
            'requested_time_out' => 'datetime',
            'requested_break_out' => 'datetime',
            'requested_break_in' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function timeLog(): BelongsTo
    {
        return $this->belongsTo(TimeLog::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
