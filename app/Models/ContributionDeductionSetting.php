<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContributionDeductionSetting extends Model
{
    protected $fillable = [
        'company_id',
        'department_id',
        'salary_type_id',
        'contribution_type_id',
        'deduction_schedule',
        'first_cutoff_percent',
        'second_cutoff_percent',
        'week_1_percent',
        'week_2_percent',
        'week_3_percent',
        'week_4_percent',
        'effective_from',
        'effective_to',
        'is_status',
    ];

    protected function casts(): array
    {
        return [
            'first_cutoff_percent' => 'decimal:2',
            'second_cutoff_percent' => 'decimal:2',
            'week_1_percent' => 'decimal:2',
            'week_2_percent' => 'decimal:2',
            'week_3_percent' => 'decimal:2',
            'week_4_percent' => 'decimal:2',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_status' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function salaryType(): BelongsTo
    {
        return $this->belongsTo(SalaryType::class);
    }

    public function contributionType(): BelongsTo
    {
        return $this->belongsTo(ContributionType::class);
    }
}
