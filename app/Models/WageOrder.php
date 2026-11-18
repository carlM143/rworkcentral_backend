<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WageOrder extends Model
{
    /** Philippine regions (code => name). */
    public const REGIONS = [
        'NCR' => 'National Capital Region',
        'CAR' => 'Cordillera Administrative Region',
        'I' => 'Region I – Ilocos Region',
        'II' => 'Region II – Cagayan Valley',
        'III' => 'Region III – Central Luzon',
        'IV-A' => 'Region IV-A – CALABARZON',
        'IV-B' => 'MIMAROPA',
        'V' => 'Region V – Bicol Region',
        'VI' => 'Region VI – Western Visayas',
        'NIR' => 'Negros Island Region',
        'VII' => 'Region VII – Central Visayas',
        'VIII' => 'Region VIII – Eastern Visayas',
        'IX' => 'Region IX – Zamboanga Peninsula',
        'X' => 'Region X – Northern Mindanao',
        'XI' => 'Region XI – Davao Region',
        'XII' => 'Region XII – SOCCSKSARGEN',
        'XIII' => 'Region XIII – Caraga',
        'BARMM' => 'Bangsamoro (BARMM)',
    ];

    /** Wage classifications used in wage orders (key => label). */
    public const CLASSIFICATIONS = [
        'non_agriculture' => 'Non-Agriculture',
        'agriculture_plantation' => 'Agriculture (Plantation)',
        'agriculture_non_plantation' => 'Agriculture (Non-Plantation)',
        'retail_service' => 'Retail / Service (small establishments)',
        'other' => 'Other',
    ];

    protected $fillable = [
        'region_code',
        'order_no',
        'title',
        'effective_date',
        'issued_date',
        'published_date',
        'status',
        'source',
        'remarks',
        'document_path',
        'document_name',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'issued_date' => 'date',
            'published_date' => 'date',
        ];
    }

    public function rates(): HasMany
    {
        return $this->hasMany(WageOrderRate::class)->orderBy('classification');
    }

    public function salaryAdjustments(): HasMany
    {
        return $this->hasMany(SalaryAdjustment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}