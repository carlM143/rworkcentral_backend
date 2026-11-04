<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyCalendarEvent extends Model
{
    protected $fillable = ['name', 'event_date', 'event_time', 'description', 'applies_to'];

    protected function casts(): array
    {
        return ['event_date' => 'date'];
    }

    public function locations()
    {
        return $this->belongsToMany(Location::class, 'company_calendar_event_locations');
    }
}
