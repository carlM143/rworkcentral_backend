<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContributionType extends Model
{
    protected $fillable = [
        'contribution_code',
        'contribution_name',
        'provider',
        'is_status',
    ];

    protected function casts(): array
    {
        return [
            'is_status' => 'boolean',
        ];
    }

    public function rateSchedules(): HasMany
    {
        return $this->hasMany(ContributionRateSchedule::class);
    }

    public function deductionSettings(): HasMany
    {
        return $this->hasMany(ContributionDeductionSetting::class);
    }

    public function payrollContributions(): HasMany
    {
        return $this->hasMany(PayrollContribution::class);
    }
}
