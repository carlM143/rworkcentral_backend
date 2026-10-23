<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactInformation extends Model
{
    protected $table = 'contact_information';

    protected $fillable = [
        'employee_id',
        'email',
        'mobile',
        'residential_address',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}