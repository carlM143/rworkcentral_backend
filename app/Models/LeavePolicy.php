<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeavePolicy extends Model
{
    protected $fillable = [
        'leave_type_id',
        'employment_type_id',
        'is_eligible',
        'is_paid',
        'annual_credits',
        'min_service_months',
        'allow_carry_over',
        'max_carry_over_days',
        'requires_approval',
        'usage_window',
        'validity_period',
        'effective_from',
        'effective_to',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'is_eligible' => 'boolean',
            'is_paid' => 'boolean',
            'annual_credits' => 'decimal:2',
            'allow_carry_over' => 'boolean',
            'max_carry_over_days' => 'decimal:2',
            'requires_approval' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function employmentType()
    {
        return $this->belongsTo(EmploymentType::class);
    }

    public function leaveRequests()
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function leaveCreditTransactions()
    {
        return $this->hasMany(LeaveCreditTransaction::class);
    }
}