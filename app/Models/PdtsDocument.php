<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PdtsDocument extends Model
{
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