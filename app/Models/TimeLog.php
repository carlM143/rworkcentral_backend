<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TimeLog extends Model
{
    protected $fillable = [
        'employee_id',
        'work_date',
        'time_in',
        'time_out',
        'break_out',
        'break_in',
        'late_minutes',
        'undertime_minutes',
        'worked_minutes',
        'overtime_minutes',
        'work_schedule_id',
        'scheduled_time_in',
        'scheduled_time_out',
        'raw_late_minutes',
        'grace_period_minutes',
        'chargeable_late_minutes',
        'source',
        'remarks',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'time_in' => 'datetime',
            'time_out' => 'datetime',
            'break_out' => 'datetime',
            'break_in' => 'datetime',
            'late_minutes' => 'integer',
            'undertime_minutes' => 'integer',
            'worked_minutes' => 'integer',
            'overtime_minutes' => 'integer',
            'scheduled_time_in' => 'datetime',
            'scheduled_time_out' => 'datetime',
            'raw_late_minutes' => 'integer',
            'grace_period_minutes' => 'integer',
            'chargeable_late_minutes' => 'integer',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function overtimeRequests()
    {
        return $this->hasMany(OvertimeRequest::class);
    }

    public function undertimeRequests()
    {
        return $this->hasMany(UndertimeRequest::class);
    }

    public function lateAdjustments()
    {
        return $this->hasMany(LateAdjustment::class);
    }

    public function latestLateAdjustment()
    {
        return $this->hasOne(LateAdjustment::class)
            ->latestOfMany();
    }

    public function correctionRequests()
    {
        return $this->hasMany(
            \App\Models\TimeLogCorrectionRequest::class,
            'time_log_id'
        );
    } 
    public function attendanceScans(): HasMany
    {
        return $this->hasMany(AttendanceScan::class);
    }
    public function holidayPay(): HasOne
    {
        return $this->hasOne(PayrollHolidayPay::class);
    }
}