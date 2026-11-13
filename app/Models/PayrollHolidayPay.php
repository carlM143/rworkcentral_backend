<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollHolidayPay extends Model
{
    protected $fillable = [
        'payroll_id',
        'employee_id',
        'holiday_id',
        'holiday_pay_rule_id',
        'time_log_id',
        'holiday_date',
        'scenario',
        'worked_minutes',
        'overtime_minutes',
        'daily_rate',
        'rate_percent',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'holiday_date' => 'date',
            'worked_minutes' => 'integer',
            'overtime_minutes' => 'integer',
            'daily_rate' => 'decimal:2',
            'rate_percent' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function holiday(): BelongsTo
    {
        return $this->belongsTo(Holiday::class);
    }

    public function holidayPayRule(): BelongsTo
    {
        return $this->belongsTo(HolidayPayRule::class);
    }

    public function timeLog(): BelongsTo
    {
        return $this->belongsTo(TimeLog::class);
    }
}