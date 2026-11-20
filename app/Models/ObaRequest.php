<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ObaRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_DEPT_HEAD_APPROVED = 'dept_head_approved';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    /** Statuses that still block an employee's time slot. */
    public const ACTIVE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_DEPT_HEAD_APPROVED,
        self::STATUS_APPROVED,
    ];

    protected $table = 'oba_requests';

    protected $fillable = [
        'employee_id',          // the employee who FILED the form
        'dept_head_id',         // users.id of the department head who must sign
        'dept_head_acted_at',
        'approved_by',          // HR
        'reason',
        'destination',
        'time_from',
        'time_to',
        'date_request',
        'status',
        'rejection_reason',
    ];

    protected $casts = [
        'time_from' => 'datetime',
        'time_to' => 'datetime',
        'date_request' => 'datetime',
        'dept_head_acted_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function filer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function deptHead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dept_head_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function obaEmployees(): HasMany
    {
        return $this->hasMany(ObaEmployee::class, 'oba_request_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ObaImage::class, 'oba_request_id');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }
}