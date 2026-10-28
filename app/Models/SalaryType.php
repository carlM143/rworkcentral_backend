<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalaryType extends Model
{
    protected $fillable = ['type_name'];

    public function payrolls(): HasMany
    {
        return $this->hasMany(Payroll::class, 'salary_type_id');
    }
}