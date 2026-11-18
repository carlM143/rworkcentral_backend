<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeLifecycleStage extends Model
{
    public const REGULARIZATION_POLICY = 'Probationary Regularization Policy';
    public const REGULARIZATION_CODE = 'M6_REGULARIZATION';
    public const REGULARIZATION_WINDOW_START = 3;
    public const REGULARIZATION_WINDOW_END = 6;
    public const RETIRED_REGULARIZATION_CODES = ['M1_CHECKIN', 'M3_EVAL', 'M5_REVIEW'];

    protected $fillable = [
        'lifecycle_policy_id',
        'stage_name',
        'stage_code',
        'months_after_hire',
        'end_month_after_hire',
        'is_managed_regularization',
        'sort_order',
        'status',
        'requires_attachment',
    ];

    protected function casts(): array
    {
        return [
            'months_after_hire' => 'integer',
            'end_month_after_hire' => 'integer',
            'is_managed_regularization' => 'boolean',
            'status' => 'boolean',
            'requires_attachment' => 'boolean',
        ];
    }

    public function targetMonthAfterHire(): int
    {
        return $this->end_month_after_hire ?? $this->months_after_hire;
    }

    public function lifecyclePolicy()
    {
        return $this->belongsTo(EmployeeLifecyclePolicy::class, 'lifecycle_policy_id');
    }

    public function milestones()
    {
        return $this->hasMany(EmployeeLifecycleMilestone::class, 'lifecycle_stage_id');
    }
}
