<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HolidayType extends Model
{
    protected $fillable = [
        'user_id',
        'holiday_type_name',
        'description',
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
}