<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LateAdjustment extends Model
{
    protected $fillable = [
        'time_log_id',
        'employee_id',
        'requested_by',
        'reviewed_by',

        'raw_late_minutes',
        'grace_period_minutes',
        'chargeable_late_minutes',

        'requested_waiver_minutes',
        'approved_waiver_minutes',
        'final_late_minutes',

        'status',
        'reason',
        'review_remarks',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'raw_late_minutes' => 'integer',
            'grace_period_minutes' => 'integer',
            'chargeable_late_minutes' => 'integer',
            'requested_waiver_minutes' => 'integer',
            'approved_waiver_minutes' => 'integer',
            'final_late_minutes' => 'integer',
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
        return $this->belongsTo(
            User::class,
            'requested_by'
        );
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'reviewed_by'
        );
    }
}
