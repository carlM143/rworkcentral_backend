<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeePayrollGroupAssignment extends Model
{
    protected $table = 'employee_payroll_group_assignments';

    protected $fillable = [
        'employee_id',
        'payroll_group_id',
        'effective_from',
        'effective_to',
        'assigned_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payrollGroup(): BelongsTo
    {
        return $this->belongsTo(PayrollGroup::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
