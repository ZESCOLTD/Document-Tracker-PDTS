<?php

namespace App\Models;

use App\Enums\MovementStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One handover of a physical document. Changes go through CustodyService.
 */
class DocumentMovement extends Model
{
    public const RETURN_CATEGORIES = [
        'wrong_document' => 'Wrong document',
        'missing_item' => 'Missing item',
        'wrong_signature' => 'Wrong or missing signature',
        'other' => 'Other',
    ];

    protected $table = 'pdts_document_movements';

    protected $fillable = [
        'pdts_document_id',
        'sender_id',
        'recipient_id',
        'messenger_id',
        'picked_up_at',
        'sender_office',
        'recipient_office',
        'status',
        'forwarded_at',
        'remarks',
        'return_category',
        'acknowledged_at',
        'acknowledged_by',
        'new_custodian_id',
        'acknowledged_office',
        'closed_at',
        'closed_by',
        'close_reason',
        'open_document_id',
    ];

    protected $casts = [
        'status' => MovementStatus::class,
        'forwarded_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new LogicException('Document movements cannot be deleted.');
        });
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(PdtsDocument::class, 'pdts_document_id');
    }

    // History must keep naming people whose accounts were later removed.
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id')->withTrashed();
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id')->withTrashed();
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by')->withTrashed();
    }

    public function messenger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'messenger_id')->withTrashed();
    }

    public function newCustodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'new_custodian_id')->withTrashed();
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by')->withTrashed();
    }

    public function isPending(): bool
    {
        return $this->status === MovementStatus::AwaitingReceipt;
    }

    public function isReturn(): bool
    {
        return $this->return_category !== null;
    }

    public function returnCategoryLabel(): ?string
    {
        return self::RETURN_CATEGORIES[$this->return_category] ?? null;
    }
}
