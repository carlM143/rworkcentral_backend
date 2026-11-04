<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryAdjustment extends Model
{
    protected $fillable = [
        'employee_id',
        'salary_setup_id',
        'adjustment_type',
        'previous_basic_salary',
        'new_basic_salary',
        'previous_allowance',
        'new_allowance',
        'effective_date',
        'reason',
        'approved_by',
        'approved_at',
        'applied_by',
        'applied_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'previous_basic_salary' => 'decimal:2',
            'new_basic_salary' => 'decimal:2',
            'previous_allowance' => 'decimal:2',
            'new_allowance' => 'decimal:2',

            'effective_date' => 'date',
            'approved_at' => 'date',
            'applied_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function salarySetup(): BelongsTo
    {
        return $this->belongsTo(SalarySetup::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}
