<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContributionRateSchedule extends Model
{
    protected $fillable = [
        'contribution_type_id',
        'calculation_method',
        'salary_basis',
        'employee_rate',
        'employer_rate',
        'employee_amount',
        'employer_amount',
        'frequency',
        'minimum_salary_base',
        'maximum_salary_base',
        'effective_from',
        'effective_to',
        'is_status',
    ];

    protected function casts(): array
    {
        return [
            'employee_rate' => 'decimal:5',
            'employer_rate' => 'decimal:5',
            'employee_amount' => 'decimal:2',
            'employer_amount' => 'decimal:2',
            'minimum_salary_base' => 'decimal:2',
            'maximum_salary_base' => 'decimal:2',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_status' => 'boolean',
        ];
    }

    public function contributionType(): BelongsTo
    {
        return $this->belongsTo(ContributionType::class);
    }

    public function brackets(): HasMany
    {
        return $this->hasMany(ContributionRateBracket::class);
    }

    public function payrollContributions(): HasMany
    {
        return $this->hasMany(PayrollContribution::class);
    }

    public function changeLogs(): HasMany
    {
        return $this->hasMany(ContributionChangeLog::class, 'contribution_rate_schedule_id');
    }
}
