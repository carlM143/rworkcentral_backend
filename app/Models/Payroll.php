<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'status',
        'daily_rate',
        'days_worked',
        'worked_minutes',
        'basic_pay',
        'allowance_pay',
        'overtime_minutes',
        'overtime_pay',
        'late_minutes',
        'late_deduction',
        'undertime_minutes',
        'undertime_deduction',
        'unpaid_leave_days',
        'unpaid_leave_deduction',
        'holiday_pay',
        'gross_pay',
        'contributions_total',
        'loan_deductions_total',
        'withholding_tax',
        'total_deductions',
        'net_pay',
        'breakdown',
        'generated_by',
        'generated_at',
        'finalized_by',
        'finalized_at',
    ];

    protected $hidden = [
        'account_number',
    ];

    protected function casts(): array
    {
        return [
            'basic_salary' => 'decimal:2',
            'allowance' => 'decimal:2',
            'daily_rate' => 'decimal:2',
            'days_worked' => 'integer',
            'worked_minutes' => 'integer',
            'basic_pay' => 'decimal:2',
            'allowance_pay' => 'decimal:2',
            'overtime_minutes' => 'integer',
            'overtime_pay' => 'decimal:2',
            'late_minutes' => 'integer',
            'late_deduction' => 'decimal:2',
            'undertime_minutes' => 'integer',
            'undertime_deduction' => 'decimal:2',
            'unpaid_leave_days' => 'decimal:2',
            'unpaid_leave_deduction' => 'decimal:2',
            'gross_pay' => 'decimal:2',
            'contributions_total' => 'decimal:2',
            'loan_deductions_total' => 'decimal:2',
            'withholding_tax' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'net_pay' => 'decimal:2',
            'breakdown' => 'array',
            'generated_at' => 'datetime',
            'finalized_at' => 'datetime',
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
        return $this->belongsTo(SalaryType::class);
    }

    public function loanTransactions(): HasMany
    {
        return $this->hasMany(EmployeeLoanTransaction::class);
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(PayrollContribution::class);
    }

    public function holidayPays(): HasMany
    {
        return $this->hasMany(PayrollHolidayPay::class);
    }
}