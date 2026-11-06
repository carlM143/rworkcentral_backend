<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDepartmentTransfer extends Model
{
    protected $table = 'employee_departments_transfer';

    protected $fillable = [
        'employee_id',
        'from_department_id',
        'to_department_id',
        'effective_date',
        'reason',
        'status',
        'created_by',
        'reviewed_by',
        'reviewed_at',
        'review_remarks',
        'applied_by',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'reviewed_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function fromDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'from_department_id');
    }

    public function toDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'to_department_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function applier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}
