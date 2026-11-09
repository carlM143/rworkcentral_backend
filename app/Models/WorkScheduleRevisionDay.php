<?php

namespace App\Models;

// app/Models/WorkScheduleRevisionDay.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkScheduleRevisionDay extends Model
{
    protected $fillable = ['work_schedule_revision_id', 'day_of_week', 'is_working_day', 'time_in', 'time_out'];
}