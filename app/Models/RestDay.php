<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestDay extends Model
{
    protected $fillable = [
        'rest_day',
    ];

    public function employments()
    {
        return $this->hasMany(Employment::class, 'restday_id');
    }
}