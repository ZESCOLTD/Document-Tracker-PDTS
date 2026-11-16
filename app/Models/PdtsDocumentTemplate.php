<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Word template internal documents are generated from. The built-in ZESCO
 * memorandum has no file: PDTS draws it. Uploaded templates are .docx files
 * with ${placeholders} that PDTS fills in.
 */
class PdtsDocumentTemplate extends Model
{
    /** Placeholders PDTS fills in, with what each one becomes. */
    public const PLACEHOLDERS = [
        'reference' => 'PDTS tracking reference, e.g. OMTS-2026-0001',
        'qr_code' => 'QR code picture (put it in the footer)',
        'title' => 'Document title',
        'subject' => 'Subject line',
        'to' => 'Addressed to',
        'from' => 'From',
        'cc' => 'Copied to',
        'office_reference' => 'The office\'s own file reference',
        'date' => 'Date of the document',
        'author' => 'Name of the person who created it',
        'directorate' => 'Originating directorate',
        'department' => 'Originating department',
        'office' => 'Originating office',
    ];

    protected $fillable = [
        'name',
        'document_type_id',
        'storage_path',
        'original_filename',
        'is_builtin',
        'is_active',
        'uploaded_by',
    ];

    protected $casts = [
        'is_builtin' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(PdtsDocumentType::class, 'document_type_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PdtsDocument::class, 'template_id');
    }
}
