<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContributionChangeLog extends Model
{
    public $timestamps = false;

    protected $table = 'contribution_change_logs';

    protected $fillable = [
        'contribution_type_id',
        'contribution_rate_schedule_id',
        'company_id',
        'entity_type',
        'entity_id',
        'action',
        'old_values',
        'new_values',
        'reason',
        'changed_by',
        'actor_name',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function contributionType(): BelongsTo
    {
        return $this->belongsTo(ContributionType::class);
    }

    public function rateSchedule(): BelongsTo
    {
        return $this->belongsTo(ContributionRateSchedule::class, 'contribution_rate_schedule_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /**
     * Record a contribution change log entry when an actor user is available.
     */
    public static function record(array $attributes): ?self
    {
        $user = auth()->user() ?? User::query()->orderBy('id')->first();

        $changedBy = $attributes['changed_by'] ?? $user?->id;
        if (!$changedBy) {
            return null;
        }

        $actorName = $attributes['actor_name']
            ?? ($user?->name ?: ($user?->email ?: 'System Administrator'));

        return self::create([
            'contribution_type_id' => $attributes['contribution_type_id'],
            'contribution_rate_schedule_id' => $attributes['contribution_rate_schedule_id'] ?? null,
            'company_id' => $attributes['company_id'] ?? null,
            'entity_type' => $attributes['entity_type'],
            'entity_id' => $attributes['entity_id'],
            'action' => $attributes['action'],
            'old_values' => $attributes['old_values'] ?? null,
            'new_values' => $attributes['new_values'] ?? null,
            'reason' => $attributes['reason'] ?? null,
            'changed_by' => $changedBy,
            'actor_name' => $actorName,
            'created_at' => $attributes['created_at'] ?? now(),
        ]);
    }
}
