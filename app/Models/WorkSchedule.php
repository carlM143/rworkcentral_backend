<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkSchedule extends Model
{
    protected $fillable = [
        'schedule_type_id',
        'schedule_code',
        'schedule_name',
        'time_in',
        'time_out',
        'required_work_hours',
        'grace_period',
        'break_start',
        'break_end',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'required_work_hours' => 'decimal:2',
            'grace_period' => 'integer',
            'status' => 'boolean',
        ];
    }

    public function scheduleType()
    {
        return $this->belongsTo(ScheduleType::class);
    }

    public function employments()
    {
        return $this->hasMany(Employment::class);
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
