<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    protected $fillable = [
        'user_id',
        'holiday_type_id',
        'holiday_name',
        'holiday_date',
        'description',
        'applied_to',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'holiday_date' => 'date',
            'status' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function holidayType()
    {
        return $this->belongsTo(HolidayType::class);
    }

    public function holidayCompanies()
    {
        return $this->hasMany(HolidayCompany::class);
    }

    public function companies()
    {
        return $this->belongsToMany(Company::class, 'holiday_companies')->withTimestamps();
    }
}