<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HolidayCompany extends Model
{
    protected $fillable = [
        'holiday_id',
        'company_id',
    ];

    public function holiday()
    {
        return $this->belongsTo(Holiday::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}