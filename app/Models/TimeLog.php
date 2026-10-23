<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
}