<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class WorkSchedule extends Model
{
    protected $fillable = [
        'schedule_type_id',
        'schedule_code',
        'schedule_name',
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

    public function revisions(): HasMany
    {
        return $this->hasMany(WorkScheduleRevision::class);
    }

    // The revision in effect today
    public function currentRevision(): HasOne
    {
        return $this->hasOne(WorkScheduleRevision::class)->ofMany(
            ['effective_from' => 'max', 'id' => 'max'],
            fn ($q) => $q->where('effective_from', '<=', today()->toDateString())
        );
    }

    // The next scheduled change, if any
    public function upcomingRevision(): HasOne
    {
        return $this->hasOne(WorkScheduleRevision::class)->ofMany(
            ['effective_from' => 'min', 'id' => 'min'],
            fn ($q) => $q->where('effective_from', '>', today()->toDateString())
        );
    }

    // Use this in payroll / timekeeping, never the parent columns
    public function revisionFor($date): ?WorkScheduleRevision
    {
        return $this->revisions()
            ->where('effective_from', '<=', Carbon::parse($date)->toDateString())
            ->orderByDesc('effective_from')
            ->with('days')
            ->first();
    }
}
