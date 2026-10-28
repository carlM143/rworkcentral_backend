<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model
{
    protected $fillable = [
        'user_id',
        'leave_code',
        'leave_name',
        'status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function leavePolicies()
    {
        return $this->hasMany(LeavePolicy::class);
    }

    public function leaveRequests()
    {
        return $this->hasMany(LeaveRequest::class);
    }
}