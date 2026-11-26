<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved version of an internal draft: everything that goes into the
 * document (subject, title, the template's fields, the signatory) as it was
 * when saved. Kept for every save, so changes after a return can be seen.
 */
class PdtsDocumentVersion extends Model
{
    public const UPDATED_AT = null;

    public const REASONS = [
        'created' => 'Draft created',
        'edited' => 'Draft edited',
        'corrected' => 'Corrected after being returned',
    ];

    protected $fillable = ['pdts_document_id', 'version', 'created_by', 'reason', 'data', 'changed_fields'];

    protected $casts = [
        'data' => 'array',
        'changed_fields' => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(PdtsDocument::class, 'pdts_document_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason] ?? $this->reason;
    }
}
