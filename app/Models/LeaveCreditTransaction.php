<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveCreditTransaction extends Model
{
    protected $fillable = [
        'employee_id',
        'leave_policy_id',
        'leave_request_id',
        'transaction_type',
        'days_delta',
        'cycle_start',
        'cycle_end',
        'effective_date',
        'reference_key',
        'remarks',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'days_delta' => 'decimal:2',
            'cycle_start' => 'date',
            'cycle_end' => 'date',
            'effective_date' => 'date',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function leavePolicy()
    {
        return $this->belongsTo(LeavePolicy::class);
    }

    public function leaveRequest()
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
