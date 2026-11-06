<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PayrollGroup extends Model
{
    protected $fillable = [
        'group_name',
        'description',
        'frequency',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function payrolls(): HasMany
    {
        return $this->hasMany(Payroll::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeePayrollGroupAssignment::class);
    }

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(
            Employee::class,
            'employee_payroll_group_assignments',
            'payroll_group_id',
            'employee_id'
        )
        ->withPivot([
            'id',
            'effective_from',
            'effective_to',
            'assigned_by',
        ])
        ->withTimestamps();
    }
}
