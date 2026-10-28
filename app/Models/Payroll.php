<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payroll extends Model
{
    protected $fillable = [
        'employee_id',
        'payroll_group_id',
        'payroll_period_id',
        'salary_type_id',
        'basic_salary',
        'allowance',
        'bank_name',
        'account_name',
        'account_number',
    ];

    protected $hidden = [
        'account_number',
    ];

    protected function casts(): array
    {
        return [
            'basic_salary' => 'decimal:2',
            'allowance' => 'decimal:2',
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

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class);
    }

    public function salaryType(): BelongsTo
    {
        return $this->belongsTo(SalaryType::class, 'salary_type_id');
    }
}
