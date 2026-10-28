<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeLifecycleStage extends Model
{
    protected $fillable = [
        'lifecycle_policy_id',
        'stage_name',
        'stage_code',
        'months_after_hire',
        'sort_order',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
        ];
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