<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkScheduleDay extends Model
{
    protected $fillable = [
        'work_schedule_id',
        'day_of_week',
        'is_working_day',
        'time_in',
        'time_out',
    ];

    protected function casts(): array
    {
        return [
            'is_working_day' => 'boolean',
        ];
    }

    public function workSchedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class);
    }
}
