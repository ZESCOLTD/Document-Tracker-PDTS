<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdtsUserDelegation extends Model
{
    protected $fillable = [
        'principal_user_id',
        'delegate_user_id',
        'can_receive',
        'can_prepare',
        'granted_by',
        'starts_at',
        'expires_at',
        'revoked_at',
        'reason',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'can_receive' => 'boolean',
        'can_prepare' => 'boolean',
    ];

    /** Delegations that let the delegate receive (acknowledge) documents for the principal. */
    public function scopeReceiving(Builder $query): Builder
    {
        return $query->where('can_receive', true);
    }

    /** Delegations that let the delegate prepare documents for the principal to sign. */
    public function scopePreparing(Builder $query): Builder
    {
        return $query->where('can_prepare', true);
    }

    public function isActive(): bool
    {
        return ! $this->revoked_at && $this->starts_at->lte(now()) && (! $this->expires_at || $this->expires_at->gt(now()));
    }

    public function principal(): BelongsTo
    {
        return $this->belongsTo(User::class, 'principal_user_id');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_user_id');
    }

    public function granter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function scopeActiveAt(Builder $query, ?\DateTimeInterface $at = null): Builder
    {
        $at ??= now();

        return $query
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', $at)
            ->where(fn (Builder $query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', $at));
    }
}
