<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeAllowance extends Model
{
    protected $table = 'employee_allowance';

    protected $fillable = [
        'salary_setup_id',
        'allowance_name',
        'allowance',
    ];

    protected $casts = [
        'allowance' => 'decimal:2',
    ];

    public function salarySetup(): BelongsTo
    {
        return $this->belongsTo(SalarySetup::class, 'salary_setup_id');
    }
}