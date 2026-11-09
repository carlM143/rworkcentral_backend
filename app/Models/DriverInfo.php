<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DriverInfo extends Model
{
    protected $table = 'drivers_info';

    protected $fillable = [
        'employment_id',
        'licence_no',
        'drivers_code',
        'expire_date',
        'image_path',
    ];

    protected function casts(): array
    {
        return [
            'expire_date' => 'date',
        ];
    }

    public function employment(): BelongsTo
    {
        return $this->belongsTo(Employment::class);
    }

    public function tripTickets(): HasMany
    {
        return $this->hasMany(TripTicket::class, 'driver_id');
    }
}