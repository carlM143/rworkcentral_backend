<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkFromHome extends Model
{
    protected $table = 'work_from_home';

    protected $fillable = [
        'employee_id',
        'request_no',
        'start_date',
        'end_date',
        'number_of_days',
        'reason',
        'supervisor_name',
        'request_date',
        'remarks',
        'approved_by',
        'approved_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'request_date' => 'date',
            'approved_at' => 'date',
            'number_of_days' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function trackers(): HasMany
    {
        return $this->hasMany(WfhTracker::class, 'work_from_home_id');
    }
}
