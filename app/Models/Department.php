<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $fillable = [
        'admin_id',
        'company_id',
        'department_name',
        'department_code',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
    public function positions(): HasMany
    {
        return $this->hasMany(Position::class, 'department_id');
    }
    public function employments(): HasMany
    {
        return $this->hasMany(Employment::class, 'department_id');
    }
}
