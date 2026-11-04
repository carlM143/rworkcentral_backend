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
        'applied_by',
        'correction_type',
        'status',
        'original_time_in',
        'original_time_out',
        'requested_time',
        'reason',
        'attachment_path',
        'review_remarks',
        'reviewed_at',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'original_time_in' => 'datetime',
            'original_time_out' => 'datetime',
            'reviewed_at' => 'datetime',
            'applied_at' => 'datetime',
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

    public function applier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}
