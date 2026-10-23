<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalarySetup extends Model
{
    protected $fillable = [
        'employee_id',
        'salary_type_id',
        'basic_salary',
        'allowance',
        'bank_name',
        'account_name',
        'account_number',
    ];

    protected function casts(): array
    {
        return [
            'basic_salary' => 'decimal:2',
            'allowance' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function salaryType(): BelongsTo
    {
        return $this->belongsTo(SalaryType::class);
    }
}
