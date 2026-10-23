<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function companies()
    {
        return $this->hasMany(Company::class, 'admin_id');
    }

    public function departments()
    {
        return $this->hasMany(Department::class, 'admin_id');
    }

    public function createdLeaveTypes()
    {
        return $this->hasMany(LeaveType::class, 'user_id');
    }

    public function approvedLeaveRequests()
    {
        return $this->hasMany(LeaveRequest::class, 'approved_by');
    }

    public function lifecyclePolicies()
    {
        return $this->hasMany(EmployeeLifecyclePolicy::class);
    }

    public function approvedOvertimeRequests()
    {
        return $this->hasMany(OvertimeRequest::class, 'approved_by');
    }

    public function approvedUndertimeRequests()
    {
        return $this->hasMany(UndertimeRequest::class, 'approved_by');
    }

    public function payrollPeriods()
    {
        return $this->hasMany(PayrollPeriod::class);
    }

    public function holidayTypes()
    {
        return $this->hasMany(HolidayType::class);
    }

    public function holidays()
    {
        return $this->hasMany(Holiday::class);
    }
}
