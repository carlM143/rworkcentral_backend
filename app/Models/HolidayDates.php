<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HolidayDates extends Model
{
    protected $table = 'holiday_dates';

    protected $fillable = [
        'holiday_id',
        'holiday_date',
    ];

    protected function casts(): array
    {
        return [
            'holiday_date' => 'date:Y-m-d',
        ];
    }

    public function holiday()
    {
        return $this->belongsTo(Holiday::class);
    }
}
