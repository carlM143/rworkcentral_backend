<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OvertimeRequest extends Model
{
    protected $fillable = [
        'employee_id',
        'work_schedule_id',
        'time_log_id',
        'request_no',
        'ot_date',
        'start_at',
        'end_at',
        'reason',
        'approved_by',
        'approved_at',
        'file_path',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'ot_date' => 'date',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
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