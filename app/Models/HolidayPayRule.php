<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HolidayPayRule extends Model
{
    public const SCENARIOS = [
        'unworked',
        'worked',
        'worked_overtime',
        'rest_day_worked',
        'rest_day_overtime',
    ];

    public const APPLIES_TO = ['all', 'daily_paid', 'monthly_paid'];

    protected $fillable = [
        'holiday_type_id',
        'scenario',
        'rate_percent',
        'applies_to',
        'effective_from',
        'effective_to',
        'is_status',
    ];

    protected function casts(): array
    {
        return [
            'rate_percent' => 'decimal:2',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_status' => 'boolean',
        ];
    }

    public function holidayType(): BelongsTo
    {
        return $this->belongsTo(HolidayType::class);
    }

    public function payrollHolidayPays(): HasMany
    {
        return $this->hasMany(PayrollHolidayPay::class);
    }

    /** Active rules in effect on a given date (Y-m-d). */
    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query
            ->where('is_status', true)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $date));
    }
}