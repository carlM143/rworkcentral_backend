<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleType extends Model
{
    protected $fillable = [
        'type_name',
    ];

    public function workSchedules()
    {
        return $this->hasMany(WorkSchedule::class);
    }
}