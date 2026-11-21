<?php

namespace App\Models;

use App\Services\Pdts\Templates\DocxTemplateFiller;
use App\Services\Pdts\Templates\TemplatePreparer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * A ZESCO Word template internal documents are generated from, uploaded in
 * Configurations. At upload PDTS marks the heading lines and adds the QR code
 * (TemplatePreparer); for each document it fills the ${placeholders}.
 *
 * The New Internal Document form follows the template: it asks only for the
 * heading fields the template uses, and any placeholder PDTS does not know
 * (e.g. ${amount}) becomes an extra field of its own.
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

    /** Heading fields the author types in, shown only when the template uses them. */
    public const HEADING_FIELDS = ['to', 'from', 'cc', 'office_reference', 'date'];

    protected $fillable = [
        'name',
        'kind',
        'description',
        'document_type_id',
        'storage_path',
        'original_path',
        'original_filename',
        'placeholders',
        'field_labels',
        'qr_page',
        'qr_x_mm',
        'qr_y_mm',
        'qr_size_mm',
        'page_count',
        'configured_at',
        'is_active',
        'uploaded_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'placeholders' => 'array',
        'field_labels' => 'array',
        'qr_x_mm' => 'float',
        'qr_y_mm' => 'float',
        'qr_size_mm' => 'float',
        'page_count' => 'integer',
        'configured_at' => 'datetime',
    ];

    public function isPdf(): bool
    {
        return $this->kind === 'pdf';
    }

    /** Ready for authors: the QR code has been placed and the template saved. */
    public function isConfigured(): bool
    {
        return $this->configured_at !== null;
    }

    /**
     * Where the QR code goes, or null when it was typed into the file as
     * ${qr_code} (older uploads) or has not been placed yet.
     *
     * @return array{page: string, x: float, y: float, size: float}|null
     */
    public function placement(): ?array
    {
        if ($this->qr_page === null || $this->qr_x_mm === null) {
            return null;
        }

        return ['page' => $this->qr_page, 'x' => $this->qr_x_mm, 'y' => $this->qr_y_mm, 'size' => $this->qr_size_mm ?? 22.0];
    }

    /** "Every page · 22 mm, 170 mm from left, 12 mm from top", in words. */
    public function qrLabel(): string
    {
        $placement = $this->placement();
        if (! $this->isConfigured()) {
            return 'QR code not placed yet';
        }
        if ($placement === null) {
            return 'Where ${qr_code} is typed in the file';
        }

        $page = match ($placement['page']) {
            'all' => 'Every page',
            'first' => 'First page',
            default => 'Page '.$placement['page'],
        };

        return sprintf('%s · %s mm, %s mm from left, %s mm from top', $page,
            rtrim(rtrim(number_format($placement['size'], 1), '0'), '.'),
            rtrim(rtrim(number_format($placement['x'], 1), '0'), '.'),
            rtrim(rtrim(number_format($placement['y'], 1), '0'), '.'));
    }

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

    /**
     * Every ${placeholder} this template contains. Older uploads saved before
     * placeholders were recorded are read from their file once and remembered.
     *
     * @return list<string>
     */
    public function placeholderNames(): array
    {
        if ($this->placeholders === null && $this->storage_path) {
            try {
                $names = app(DocxTemplateFiller::class)->placeholdersIn(Storage::disk('private')->path($this->storage_path));
            } catch (Throwable) {
                return [];
            }

            $this->placeholders = $names;
            if ($this->exists) {
                $this->saveQuietly();
            }
        }

        return array_values($this->placeholders ?? []);
    }

    public function usesField(string $name): bool
    {
        return in_array($name, $this->placeholderNames(), true);
    }

    /**
     * Placeholders PDTS does not fill itself, as name => label, e.g.
     * ['amount' => 'Amount', 'due_date' => 'Due date']. Each becomes a field.
     *
     * @return array<string, string>
     */
    public function customFields(): array
    {
        $fields = [];

        foreach ($this->placeholderNames() as $name) {
            if (! array_key_exists($name, self::PLACEHOLDERS)) {
                // The label as written in the file; older templates: rebuilt from the name.
                $fields[$name] = $this->field_labels[$name]
                    ?? TemplatePreparer::displayLabel(str_replace('_', ' ', $name));
            }
        }

        return $fields;
    }
}
