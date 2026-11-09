<?php

namespace App\Models;


use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class WorkScheduleRevision extends Model
{
    protected $fillable = [
        'work_schedule_id', 'effective_from', 'effective_to',
        'required_work_hours', 'grace_period', 'break_start', 'break_end',
        'reason', 'created_by', 'applied_at',
    ];

    protected $casts = [
        'effective_from' => 'date:Y-m-d',
        'effective_to'   => 'date:Y-m-d',
        'applied_at'     => 'datetime',
    ];

    public function schedule()
    {
        return $this->belongsTo(WorkSchedule::class, 'work_schedule_id');
    }

    public function days()
    {
        return $this->hasMany(WorkScheduleRevisionDay::class);
    }

    // null = rest day
    public function dayConfig(CarbonInterface $date): ?WorkScheduleRevisionDay
    {
        return $this->days->firstWhere('day_of_week', $date->format('l'));
    }
}