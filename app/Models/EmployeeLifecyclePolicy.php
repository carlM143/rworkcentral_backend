<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeLifecyclePolicy extends Model
{
    protected $fillable = [
        'user_id',
        'employment_type_id',
        'name',
        'description',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function employmentType()
    {
        return $this->belongsTo(EmploymentType::class);
    }

    public function stages()
    {
        return $this->hasMany(EmployeeLifecycleStage::class, 'lifecycle_policy_id');
    }
}