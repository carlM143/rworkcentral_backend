<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UndertimeRequest extends Model
{
    protected $fillable = [
        'employee_id',
        'work_schedule_id',
        'time_log_id',
        'request_no',
        'undertime_date',
        'requested_time_out',
        'scheduled_time_out',
        'requested_minutes',
        'reason',
        'approved_by',
        'approved_at',
        'file_path',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'undertime_date' => 'date',
            'requested_time_out' => 'datetime',
            'scheduled_time_out' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function workSchedule()
    {
        return $this->belongsTo(WorkSchedule::class);
    }

    public function timeLog()
    {
        return $this->belongsTo(TimeLog::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}