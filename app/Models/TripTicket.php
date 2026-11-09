<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TripTicket extends Model
{
    protected $fillable = [
        'ticket_no',
        'requesting_department_id',
        'requested_by',
        'trip_date',
        'scheduled_departure',
        'expected_return',
        'driver_id',
        'vehicle_id',
        'destination',
        'purpose',
        'departed_at',
        'returned_at',
        'odometer_out',
        'odometer_in',
        'status',
        'remarks',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'trip_date' => 'date',
            'odometer_out' => 'integer',
            'odometer_in' => 'integer',
        ];
    }

    public function requestingDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'requesting_department_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requested_by');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(DriverInfo::class, 'driver_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function passengers(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'trip_ticket_passengers', 'trip_ticket_id', 'employee_id')
            ->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}