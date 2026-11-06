<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestDaySwitchRequest extends Model
{
    protected $fillable = [
        'request_number',
        'employee_id',
        'employment_id',
        'work_schedule_id',
        'current_schedule',
        'current_rest_day',
        'requested_rest_day',
        'original_rest_date',
        'effective_date',
        'week_start',
        'week_end',
        'reason',
        'status',
        'requested_by',
        'reviewed_by',
        'reviewed_at',
        'review_remarks',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'original_rest_date' => 'date',
            'effective_date' => 'date',
            'week_start' => 'date',
            'week_end' => 'date',
            'reviewed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function employment()
    {
        return $this->belongsTo(Employment::class);
    }

    public function workSchedule()
    {
        return $this->belongsTo(WorkSchedule::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
