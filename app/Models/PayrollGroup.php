<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollGroup extends Model
{
    protected $fillable = [
        'group_name',
    ];

    public function payrolls()
    {
        return $this->hasMany(Payroll::class);
    }
}