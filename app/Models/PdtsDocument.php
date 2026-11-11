<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\MovementStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class PdtsDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_number',
        'title',
        'subject',
        'description',
        'document_type_id',
        'originating_department',
        'originating_office',
        'registered_by',
        'current_custodian_id',
        'status',
        'registered_at',
    ];

    protected $casts = [
        'registered_at' => 'datetime',
    ];

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(PdtsDocumentType::class, 'document_type_id');
    }

    public function registrar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function currentCustodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_custodian_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(PdtsDocumentAttachment::class, 'pdts_document_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(PdtsDocumentAuditLog::class, 'pdts_document_id')->orderBy('occurred_at');
    }

    /**
     * The status as an enum. The column stays a plain string so existing
     * registration views and tests keep working.
     */
    public function statusEnum(): DocumentStatus
    {
        return DocumentStatus::from($this->status);
    }

    /**
     * The scanned copy recipients check the paper against.
     */
    public function primaryAttachment(): HasOne
    {
        return $this->hasOne(PdtsDocumentAttachment::class, 'pdts_document_id')
            ->ofMany(['is_primary' => 'max', 'id' => 'min']);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(DocumentMovement::class, 'pdts_document_id');
    }

    public function workflowInstances(): MorphMany
    {
        return $this->morphMany(WorkflowInstance::class, 'workflowable')->latest('id');
    }

    public function latestMovement(): HasOne
    {
        return $this->hasOne(DocumentMovement::class, 'pdts_document_id')->latestOfMany();
    }

    /**
     * The most recent handover that was acknowledged.
     */
    public function latestReceipt(): HasOne
    {
        return $this->hasOne(DocumentMovement::class, 'pdts_document_id')->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->where('status', MovementStatus::Acknowledged->value),
        );
    }

    public function pendingMovement(): HasOne
    {
        return $this->hasOne(DocumentMovement::class, 'pdts_document_id')
            ->where('status', MovementStatus::AwaitingReceipt->value);
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        return $query->where(function (Builder $query) use ($search): void {
            $query->where('reference_number', 'like', "%{$search}%")
                ->orWhere('title', 'like', "%{$search}%")
                ->orWhere('subject', 'like', "%{$search}%")
                ->orWhere('originating_department', 'like', "%{$search}%")
                ->orWhere('originating_office', 'like', "%{$search}%");
        });
    }
}
