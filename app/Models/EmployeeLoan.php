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
        'first_cutoff_percent',
        'second_cutoff_percent',
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
            'first_cutoff_percent' => 'decimal:2',
            'second_cutoff_percent' => 'decimal:2',
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

    /** Percent of the monthly deduction taken in this cutoff (1 = first, 2 = second). */
    public function percentForCutoff(int $cutoffNumber): float
    {
        return (float) ($cutoffNumber === 1 ? $this->first_cutoff_percent : $this->second_cutoff_percent);
    }

    /** "1st cutoff only", "2nd cutoff only" or "50% / 50% split". */
    public function deductionScheduleLabel(): string
    {
        $first = (float) $this->first_cutoff_percent;
        $second = (float) $this->second_cutoff_percent;

        return match (true) {
            $first >= 100 => '1st cutoff only',
            $second >= 100 => '2nd cutoff only',
            default => rtrim(rtrim(number_format($first, 2), '0'), '.') . '% / '
                . rtrim(rtrim(number_format($second, 2), '0'), '.') . '% split',
        };
    }
}
