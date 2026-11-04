<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    protected $fillable = ['location_name', 'city', 'province', 'status'];

    protected function casts(): array
    {
        return ['status' => 'boolean'];
    }
}
