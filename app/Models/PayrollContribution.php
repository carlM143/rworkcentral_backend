<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollContribution extends Model
{
    protected $fillable = [
        'payroll_id',
        'employee_id',
        'contribution_type_id',
        'contribution_rate_schedule_id',
        'contribution_month',
        'salary_basis_amount',
        'employee_share',
        'employer_share',
        'employer_ec_amount',
        'reference_key',
    ];

    protected function casts(): array
    {
        return [
            'contribution_month' => 'date',
            'salary_basis_amount' => 'decimal:2',
            'employee_share' => 'decimal:2',
            'employer_share' => 'decimal:2',
            'employer_ec_amount' => 'decimal:2',
        ];
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function contributionType(): BelongsTo
    {
        return $this->belongsTo(ContributionType::class);
    }

    public function rateSchedule(): BelongsTo
    {
        return $this->belongsTo(
            ContributionRateSchedule::class,
            'contribution_rate_schedule_id'
        );
    }
}
