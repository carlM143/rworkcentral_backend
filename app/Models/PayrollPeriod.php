<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollPeriod extends Model
{
    protected $fillable = [
        'user_id',
        'company_id',
        'period_name',
        'cutoff_start',
        'cutoff_end',
        'payment_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'cutoff_start' => 'date',
            'cutoff_end' => 'date',
            'payment_date' => 'date',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
    
    public function payrolls()
    {
        return $this->hasMany(Payroll::class);
    }
}