<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeOffboarding extends Model
{
    protected $fillable = ['employee_id', 'user_id', 'status', 'details'];
    protected function casts(): array
    {
        return ['details' => 'array'];
    }
    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
