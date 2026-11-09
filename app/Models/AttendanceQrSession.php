<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceQrSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'created_by',
        'name',
        'starts_at',
        'ends_at',
        'status',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(AttendanceQrToken::class);
    }

    public function scans(): HasMany
    {
        return $this->hasMany(AttendanceScan::class);
    }
}