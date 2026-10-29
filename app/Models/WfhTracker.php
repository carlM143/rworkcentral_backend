<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WfhTracker extends Model
{
    protected $table = 'wfh_tracker';

    protected $fillable = [
        'employee_id',
        'work_from_home_id',
        'date_attendance',
        'doc_path',
        'approved_by',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'date_attendance' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function workFromHome(): BelongsTo
    {
        return $this->belongsTo(
            WorkFromHome::class,
            'work_from_home_id'
        );
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function images(): HasMany
    {
        return $this->hasMany(WfhImage::class, 'wfh_tracker_id');
    }
}
