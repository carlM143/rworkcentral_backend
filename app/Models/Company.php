<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    protected $fillable = [
        'admin_id',
        'company_name',
        'location',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function payrollPeriods()
    {
        return $this->hasMany(PayrollPeriod::class);
    }

    public function holidays()
    {
        return $this->belongsToMany(Holiday::class, 'holiday_companies')->withTimestamps();
    }

    public function holidayCompanies()
    {
        return $this->hasMany(HolidayCompany::class);
    }
}