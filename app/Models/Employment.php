<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Employment extends Model
{
    protected $fillable = [
        'employee_id',
        'employment_type_id',
        'department_id',
        'position_id',
        'work_schedule_id',
        'restday_id',
        'date_hired',
        'mobile',
        'residential_address',
    ];

    protected function casts(): array
    {
        return [
            'date_hired' => 'date',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function employmentType()
    {
        return $this->belongsTo(EmploymentType::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function position()
    {
        return $this->belongsTo(Position::class);
    }

    public function workSchedule()
    {
        return $this->belongsTo(WorkSchedule::class);
    }

    public function restday()
    {
        return $this->belongsTo(RestDay::class, 'restday_id');
    }
}