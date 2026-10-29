<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContributionDeductionSetting extends Model
{
    protected $fillable = [
        'company_id',
        'contribution_type_id',
        'first_cutoff_percent',
        'second_cutoff_percent',
        'effective_from',
        'effective_to',
        'is_status',
    ];

    protected function casts(): array
    {
        return [
            'first_cutoff_percent' => 'decimal:2',
            'second_cutoff_percent' => 'decimal:2',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_status' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function contributionType(): BelongsTo
    {
        return $this->belongsTo(ContributionType::class);
    }
}
