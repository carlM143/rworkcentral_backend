<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GovernmentRequirement extends Model
{
    protected $fillable = [
        'employee_id',
        'id_name',
        'id_number',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}