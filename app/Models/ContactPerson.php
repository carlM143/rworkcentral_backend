<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactPerson extends Model
{
    protected $table = 'contact_person';

    protected $fillable = [
        'employee_id',
        'name',
        'relationship',
        'contact_number',
        'address',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}