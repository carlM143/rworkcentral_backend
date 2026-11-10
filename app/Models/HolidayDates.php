<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HolidayDates extends Model
{
     protected $fillable = [
        'holiday_id',
        'holiday_date',
    ];

    public function holiday()
    {
        return $this->belongsTo(Holiday::class);
    }
}
