<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryAdjustmentAllowance extends Model
{
    use HasFactory;

    protected $table = 'salary_adjustment_allowances';

    protected $fillable = [
        'salary_adjustment_id',
        'allowance_name',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function salaryAdjustment(): BelongsTo
    {
        return $this->belongsTo(SalaryAdjustment::class, 'salary_adjustment_id');
    }
}