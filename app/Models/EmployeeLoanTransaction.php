<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeLoanTransaction extends Model
{
    protected $fillable = [
        'employee_loan_id',
        'payroll_id',
        'transaction_type',
        'amount_delta',
        'transaction_date',
        'reference_no',
        'remarks',
        'created_by',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'amount_delta' => 'decimal:2',
            'transaction_date' => 'date',
            'status' => 'boolean',
        ];
    }

    public function employeeLoan(): BelongsTo
    {
        return $this->belongsTo(EmployeeLoan::class);
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
