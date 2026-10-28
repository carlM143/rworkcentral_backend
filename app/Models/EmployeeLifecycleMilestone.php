<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeLifecycleMilestone extends Model
{
    protected $fillable = [
        'employee_id',
        'lifecycle_stage_id',
        'due_date',
        'status',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function lifecycleStage()
    {
        return $this->belongsTo(EmployeeLifecycleStage::class, 'lifecycle_stage_id');
    }
}