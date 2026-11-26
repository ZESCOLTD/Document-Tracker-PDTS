<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\MovementStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

class PdtsDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_number',
        'document_reference',
        'document_date',
        'title',
        'subject',
        'description',
        'document_type_id',
        'originating_directorate',
        'originating_department',
        'originating_office',
        'registered_by',
        'current_custodian_id',
        'status',
        'registered_at',
        'scan_token',
        'origin',
        'template_id',
        'template_data',
        'signatory_id',
        'signature_status',
        'signature_note',
        'recipient_user_id',
        'cc_user_ids',
        'reply_to_id',
        'completed_at',
        'completed_by',
        'file_reference',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    /** Signing states of a draft prepared for someone else. */
    public const SIGNATURE_WITH_PREPARER = 'with_preparer';

    public const SIGNATURE_AWAITING = 'awaiting_signature';

    public const SIGNATURE_RETURNED = 'returned';

    public const SIGNATURE_SIGNED = 'signed';

    /** From a template marked as not signed: issued in PDTS without a signature. */
    public const SIGNATURE_NOT_REQUIRED = 'not_required';

    protected $casts = [
        'registered_at' => 'datetime',
        'template_data' => 'array',
        'cc_user_ids' => 'array',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'document_date' => 'date:Y-m-d',
    ];

    /** Until when it is kept, from its type's retention period, counted from completion. */
    public function retainUntil(): ?CarbonInterface
    {
        $years = $this->documentType?->retention_years;

        return $years && $this->completed_at ? $this->completed_at->copy()->addYears($years) : null;
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by')->withTrashed();
    }

    // Documents are external (received on paper) unless created from a template.
    protected $attributes = [
        'origin' => 'external',
    ];

    protected static function booted(): void
    {
        // Every document gets a random scan code for its QR link.
        static::creating(function (PdtsDocument $document): void {
            $document->scan_token ??= Str::random(40);
        });
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PdtsDocumentTemplate::class, 'template_id');
    }

    public function isDraft(): bool
    {
        return $this->status === DocumentStatus::Draft->value;
    }

    public function isInternal(): bool
    {
        return $this->origin === 'internal';
    }

    /**
     * The link the QR code holds. It carries the random scan code, never the
     * reference, and opens nothing until the person has logged in.
     *
     * It always uses the official address in APP_URL, not the address the
     * author happened to open PDTS with, so printed codes work for everyone.
     */
    public function scanUrl(): string
    {
        return rtrim((string) config('app.url'), '/').route('pdts.scan', $this->scan_token, false);
    }

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

    /** Who signs the document; the preparer (registered_by) when not prepared for someone else. */
    public function signatory(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signatory_id')->withTrashed();
    }

    /** The officer who signs: the signatory, or the person who prepared it. */
    public function signatoryId(): int
    {
        return (int) ($this->signatory_id ?: $this->registered_by);
    }

    /** Prepared by one person (a secretary) for another (the signatory). */
    public function isPreparedOnBehalf(): bool
    {
        return $this->signatory_id !== null && (int) $this->signatory_id !== (int) $this->registered_by;
    }

    /** The person the document is for, in PDTS (the TO person). */
    public function recipientUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id')->withTrashed();
    }

    /** @return Collection<int, User> people given a copy for information */
    public function copiedUsers()
    {
        return User::query()->whereIn('id', (array) ($this->cc_user_ids ?? []))->orderBy('name')->get();
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'reply_to_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PdtsDocumentVersion::class, 'pdts_document_id')->orderByDesc('version');
    }

    /** The electronic signature, when the document was signed in PDTS. */
    public function electronicSignature(): HasOne
    {
        return $this->hasOne(PdtsDocumentSignature::class, 'pdts_document_id');
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
     * The scanned copy recipients check the paper against: only the file
     * marked as the document itself, never a supporting file (an attachment
     * question's file).
     */
    public function primaryAttachment(): HasOne
    {
        return $this->hasOne(PdtsDocumentAttachment::class, 'pdts_document_id')
            ->ofMany(['id' => 'min'], fn ($query) => $query->where('is_primary', true));
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
                ->orWhere('originating_directorate', 'like', "%{$search}%")
                ->orWhere('originating_department', 'like', "%{$search}%")
                ->orWhere('originating_office', 'like', "%{$search}%");
        });
    }
}
