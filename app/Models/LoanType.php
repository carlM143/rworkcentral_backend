<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoanType extends Model
{
    protected $fillable = [
        'loan_code',
        'loan_name',
        'provider',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
        ];
    }

    public function employeeLoans(): HasMany
    {
        return $this->hasMany(EmployeeLoan::class);
    }
}
