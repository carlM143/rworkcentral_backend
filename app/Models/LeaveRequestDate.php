<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveRequestDate extends Model
{
    protected $fillable = [
        'leave_request_id',
        'leave_date',
        'day_type',
        'day_value',
    ];

    protected function casts(): array
    {
        return [
            'leave_date' => 'date',
            'day_value' => 'decimal:2',
        ];
    }

    public function leaveRequest()
    {
        return $this->belongsTo(LeaveRequest::class);
    }
}