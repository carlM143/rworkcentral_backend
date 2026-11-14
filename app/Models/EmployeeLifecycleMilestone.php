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
        'evaluation_document_id',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'datetime',
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

    public function evaluationDocument()
    {
        return $this->belongsTo(LifecycleEvaluationDocument::class);
    }
}
