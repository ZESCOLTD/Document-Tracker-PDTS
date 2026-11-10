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
    protected $table = 'pdts_document_movements';

    protected $fillable = [
        'pdts_document_id',
        'sender_id',
        'recipient_id',
        'sender_office',
        'recipient_office',
        'status',
        'forwarded_at',
        'remarks',
        'acknowledged_at',
        'acknowledged_by',
        'closed_at',
        'closed_by',
        'close_reason',
        'open_document_id',
    ];

    protected $casts = [
        'status' => MovementStatus::class,
        'forwarded_at' => 'datetime',
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

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by')->withTrashed();
    }

    public function isPending(): bool
    {
        return $this->status === MovementStatus::AwaitingReceipt;
    }
}
