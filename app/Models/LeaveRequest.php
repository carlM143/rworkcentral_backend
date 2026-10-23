<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveRequest extends Model
{
    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'leave_request_id',
        'leave_policy_id',
        'request_no',
        'request_date',
        'reason',
        'approved_by',
        'approved_at',
        'file_path',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'request_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function leavePolicy()
    {
        return $this->belongsTo(LeavePolicy::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function parentRequest()
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }

    public function relatedRequests()
    {
        return $this->hasMany(LeaveRequest::class, 'leave_request_id');
    }
    
    public function leaveDates()
    {
        return $this->hasMany(LeaveRequestDate::class);
    }
}