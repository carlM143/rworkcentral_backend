<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function days(): HasMany
    {
        return $this->hasMany(WorkScheduleDay::class)
            ->where('is_working_day', true)
            ->orderByRaw("CASE day_of_week
                WHEN 'Monday' THEN 1
                WHEN 'Tuesday' THEN 2
                WHEN 'Wednesday' THEN 3
                WHEN 'Thursday' THEN 4
                WHEN 'Friday' THEN 5
                WHEN 'Sat' THEN 6
                WHEN 'Sun' THEN 7
                ELSE 8 END");
    }
}
