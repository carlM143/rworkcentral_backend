<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HolidayType extends Model
{
    protected $fillable = [
        'type_code',
        'type_name',
        'user_id',
        'type_name',
        'description',
        'requires_prior_day_present',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function holidays()
    {
        return $this->hasMany(Holiday::class);
    }
    public function payRules(): HasMany
    {
        return $this->hasMany(HolidayPayRule::class);
    }
}
