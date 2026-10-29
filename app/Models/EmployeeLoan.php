<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeLoan extends Model
{
    protected $fillable = [
        'employee_id',
        'loan_type_id',
        'created_by',
        'loan_reference_no',
        'principal_amount',
        'total_payable_amount',
        'opening_balance',
        'deduction_amount',
        'loan_date',
        'deduction_start_date',
        'remarks',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'principal_amount' => 'decimal:2',
            'total_payable_amount' => 'decimal:2',
            'opening_balance' => 'decimal:2',
            'deduction_amount' => 'decimal:2',
            'loan_date' => 'date',
            'deduction_start_date' => 'date',
            'status' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function loanType(): BelongsTo
    {
        return $this->belongsTo(LoanType::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(EmployeeLoanTransaction::class);
    }
}
