<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * New minimum daily wage of one classification in a wage order.
 * The effective date is the wage order's.
 */
class WageOrderRate extends Model
{
    protected $fillable = [
        'wage_order_id',
        'classification',
        'daily_rate',
        'cola',
        'previous_rate',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'daily_rate' => 'decimal:2',
            'cola' => 'decimal:2',
            'previous_rate' => 'decimal:2',
        ];
    }

    public function wageOrder(): BelongsTo
    {
        return $this->belongsTo(WageOrder::class);
    }
}