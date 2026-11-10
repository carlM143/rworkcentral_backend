<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    protected $fillable = [
        'company_id',
        'applies_to',
        'is_working_day',
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
            'is_working_day' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function locations()
    {
        return $this->belongsToMany(Location::class, 'holiday_locations');
    }

    public function coveredEmployees()
    {
        return $this->belongsToMany(Employee::class, 'holiday_employee_coverage');
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

    public function holidayDates()
    {
        return $this->hasMany(HolidayDates::class);
    }
}
