<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employee extends Model
{
    protected $fillable = [
        'user_id',
        'department_id',
        'emp_id',
        'firstname',
        'lastname',
        'middlename',
        'suffix',
        'profile_photo',
        'age',
        'gender',
        'birthday',
        'civil_status',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'status' => 'boolean',
        ];
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function governmentRequirements()
    {
        return $this->hasMany(GovernmentRequirement::class);
    }

    public function contactInformation()
    {
        return $this->hasOne(ContactInformation::class);
    }

    public function employments()
    {
        return $this->hasMany(Employment::class);
    }

    public function contactPersons()
    {
        return $this->hasMany(ContactPerson::class);
    }

    public function leaveRequests()
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function lifecycleMilestones()
    {
        return $this->hasMany(EmployeeLifecycleMilestone::class);
    }

    public function overtimeRequests()
    {
        return $this->hasMany(OvertimeRequest::class);
    }

    public function undertimeRequests()
    {
        return $this->hasMany(UndertimeRequest::class);
    }

    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    public function payrolls()
    {
        return $this->hasMany(Payroll::class);
    }

    public function timeLogs()
    {
        return $this->hasMany(TimeLog::class);
    }

    public function salarySetup(): HasOne
    {
        return $this->hasOne(SalarySetup::class);
    }

    public function leaveCreditTransactions()
    {
        return $this->hasMany(LeaveCreditTransaction::class);
    }

    public function loans()
    {
        return $this->hasMany(EmployeeLoan::class);
    }
    public function payrollContributions()
    {
        return $this->hasMany(PayrollContribution::class);
    }


    public function latestEmployment(): HasOne
    {
        return $this->hasOne(Employment::class)
            ->latestOfMany();
    }
}
