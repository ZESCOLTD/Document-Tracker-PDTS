<?php

namespace App\Models;

use App\Services\Pdts\Templates\DocxTemplateFiller;
use App\Services\Pdts\Templates\TemplatePreparer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'author_upper' => 'Name of the person who created it, in capitals',
        'author_job_title' => 'Job title of the signatory (under the name on a letter)',
        'signature' => 'The author\'s signature, when the document is signed in PDTS',
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
        'field_settings',
        'manual_marks',
        'version',
        'previous_version_id',
        'superseded_at',
        'qr_page',
        'qr_x_mm',
        'qr_y_mm',
        'qr_size_mm',
        'page_count',
        'configured_at',
        'is_active',
        'is_unsigned',
        'uploaded_by',
        'source',
        'design',
    ];

    protected $casts = [
        'design' => 'array',
        'is_active' => 'boolean',
        'is_unsigned' => 'boolean',
        'placeholders' => 'array',
        'field_labels' => 'array',
        'field_settings' => 'array',
        'manual_marks' => 'array',
        'version' => 'integer',
        'superseded_at' => 'datetime',
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

    /** Made in the Template Builder, not uploaded. */
    public function isBuilt(): bool
    {
        return $this->source === 'builder' && is_array($this->design);
    }

    /** Ready for authors: the QR code has been placed and the template saved. */
    public function isConfigured(): bool
    {
        return $this->configured_at !== null;
    }

    /**
     * Documents from it are not signed (a form, notice or cover sheet): they
     * are issued in PDTS without a signature, never printed and scanned.
     * Only for Word templates without a signature spot.
     */
    public function isUnsigned(): bool
    {
        return (bool) $this->is_unsigned && ! $this->isPdf() && ! $this->supportsElectronicSignature();
    }

    /**
     * Why authors cannot be offered this template yet because of signing, or
     * null when they can: a Word template needs a signature spot, or to be
     * marked as not signed. PDF templates are filled and signed by hand.
     */
    public function signingBlocker(): ?string
    {
        if ($this->isPdf() || $this->supportsElectronicSignature() || $this->isUnsigned()) {
            return null;
        }

        return 'No signature spot. Mark the line where the signature goes, or mark the template as not signed.';
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

    /** Field types an administrator can choose for a field on the form. */
    public const FIELD_TYPES = [
        'text' => 'Text',
        'long' => 'Long text',
        'date' => 'Date',
        'number' => 'Number',
        'choice' => 'Choice list',
        'person' => 'Person in PDTS',
        'people' => 'Several people in PDTS',
        'attachment' => 'Attachment',
        'rich' => 'Formatted text (bold, lists)',
    ];

    /** Values a field can be filled with automatically (the author can still change them). */
    public const AUTO_FILL = [
        'none' => 'Author types it',
        'author_name' => 'Signatory\'s name',
        'author_title' => 'Signatory\'s job title',
        'author_man_no' => 'Signatory\'s man number',
        'author_department' => 'Signatory\'s department',
        'today' => 'Today\'s date',
    ];

    /**
     * How a field appears on the New Internal Document form, with the
     * administrator's settings over the defaults.
     *
     * @return array{label: ?string, type: string, required: ?bool, auto: string, help: string, choices: list<string>, hidden: bool}
     */
    public function fieldSetting(string $name): array
    {
        $saved = (array) (($this->field_settings ?? [])[$name] ?? []);
        $type = array_key_exists($saved['type'] ?? '', self::FIELD_TYPES) || ($saved['type'] ?? '') === 'table'
            ? $saved['type'] : (self::isWritingSpace($name) ? 'long' : 'text');
        // Formatted text and tables fill a whole space to write in, never a spot inside a line.
        if (in_array($type, ['rich', 'table'], true) && ! self::isWritingSpace($name)) {
            $type = 'long';
        }

        return [
            'label' => isset($saved['label']) && trim((string) $saved['label']) !== '' ? trim((string) $saved['label']) : null,
            'type' => $type,
            'required' => isset($saved['required']) ? (bool) $saved['required'] : null,
            'auto' => array_key_exists($saved['auto'] ?? '', self::AUTO_FILL) ? $saved['auto'] : 'none',
            'help' => trim((string) ($saved['help'] ?? '')),
            'choices' => array_values(array_filter(array_map('trim', (array) ($saved['choices'] ?? [])), fn ($c) => $c !== '')),
            // A table's column headings (tables are made in the Template Builder).
            'columns' => array_values(array_filter(array_map('trim', (array) ($saved['columns'] ?? [])), fn ($c) => $c !== '')),
            'hidden' => (bool) ($saved['hidden'] ?? false),
        ];
    }

    /** Earlier version this one replaces. */
    public function previousVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_version_id');
    }

    /** The version that replaced this one, if any. */
    public function nextVersion(): HasOne
    {
        return $this->hasOne(self::class, 'previous_version_id');
    }

    public function isSuperseded(): bool
    {
        return $this->superseded_at !== null;
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
     * Whether authors type the subject: only when the document prints it (or
     * its title). Otherwise PDTS names the document itself (documentName).
     */
    public function asksForSubject(): bool
    {
        return $this->usesField('subject') || $this->usesField('title');
    }

    /**
     * The template's own title line ("Report title", "Title of the report"),
     * whose answer names the document when there is no subject line. Never a
     * job title.
     */
    public function titleField(): ?string
    {
        foreach (array_keys($this->customFields()) as $name) {
            if (preg_match('/^(.+_)?title$|^title_of_/', $name) && ! preg_match('/job_title$/', $name)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * The document's name in PDTS when the template has no subject line: the
     * title the author typed, or else "Template - Signatory - Date", so that
     * documents can be told apart in lists, searches and the QR scan page.
     *
     * @param  array<string, mixed>  $answers  the template's own answers (custom fields)
     */
    public function documentName(array $answers, ?string $signatory, string $date): string
    {
        $title = ($field = $this->titleField()) ? trim(preg_replace('/\s+/u', ' ', (string) ($answers[$field] ?? ''))) : '';
        if ($title !== '') {
            return mb_substr($title, 0, 255);
        }

        $parts = array_filter([trim((string) $this->name), trim(preg_replace('/\s+/u', ' ', (string) $signatory)), $date]);

        return mb_substr(implode(' – ', $parts), 0, 255);
    }

    /** A space to write paragraphs in (the body, a report section), not a one-line field. */
    public static function isWritingSpace(string $name): bool
    {
        return str_starts_with($name, 'text_');
    }

    /**
     * Documents from this template can be signed in PDTS: it is a Word file
     * filled in PDTS and has a spot for the author's signature.
     */
    public function supportsElectronicSignature(): bool
    {
        return ! $this->isPdf() && $this->usesField('signature');
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
                // The administrator's label, else as written in the file; older templates: rebuilt from the name.
                $fields[$name] = $this->fieldSetting($name)['label']
                    ?? $this->field_labels[$name]
                    ?? TemplatePreparer::displayLabel(str_replace('_', ' ', $name));
            }
        }

        return $fields;
    }
}
