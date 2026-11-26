<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One electronic signing: who signed which document, when, from where, and
 * the SHA-256 fingerprint of the signed PDF so a later change can be seen.
 */
class PdtsDocumentSignature extends Model
{
    protected $fillable = [
        'pdts_document_id',
        'user_id',
        'attachment_id',
        'signature_image_id',
        'signed_at',
        'file_sha256',
        'signature_image_sha256',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'signed_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(PdtsDocument::class, 'pdts_document_id');
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    /** The exact signature image used, with who verified it. */
    public function signatureImage(): BelongsTo
    {
        return $this->belongsTo(UserSignatureImage::class, 'signature_image_id');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(PdtsDocumentAttachment::class, 'attachment_id');
    }

    /** True while the stored signed PDF is exactly the file that was signed. */
    public function fileIsUnchanged(): bool
    {
        $path = $this->attachment?->storage_path;
        if (! $path || ! Storage::disk('private')->exists($path)) {
            return false;
        }

        return hash_equals($this->file_sha256, hash('sha256', Storage::disk('private')->get($path)));
    }
}
