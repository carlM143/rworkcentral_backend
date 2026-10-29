<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContributionRateBracket extends Model
{
    protected $fillable = [
        'contribution_rate_schedule_id',
        'salary_from',
        'salary_to',
        'monthly_salary_credit',
        'employee_rate',
        'employer_rate',
        'employee_amount',
        'employer_amount',
        'employer_ec_amount',
    ];

    protected function casts(): array
    {
        return [
            'salary_from' => 'decimal:2',
            'salary_to' => 'decimal:2',
            'monthly_salary_credit' => 'decimal:2',
            'employee_rate' => 'decimal:5',
            'employer_rate' => 'decimal:5',
            'employee_amount' => 'decimal:2',
            'employer_amount' => 'decimal:2',
            'employer_ec_amount' => 'decimal:2',
        ];
    }

    public function rateSchedule(): BelongsTo
    {
        return $this->belongsTo(
            ContributionRateSchedule::class,
            'contribution_rate_schedule_id'
        );
    }
}
