<?php

namespace App\Services\Pdts;

use App\Enums\DocumentStatus;
use App\Models\PdtsDocument;
use App\Models\PdtsDocumentTemplate;
use App\Models\PdtsOffice;
use App\Models\User;
use App\Services\Pdts\Distribution\DocumentDistribution;
use App\Services\Pdts\Reports\DocumentOrigins;
use App\Services\Pdts\Signatures\PdfConverter;
use App\Services\Pdts\Signatures\PreparedDocuments;
use App\Services\Pdts\Templates\DocxPackage;
use App\Services\Pdts\Templates\DocxTemplateFiller;
use App\Services\Pdts\Templates\QrCodePng;
use App\Services\Pdts\Templates\RichText;
use App\Services\Pdts\Templates\TemplateSetup;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Internal documents start in PDTS: a draft reserves the reference and scan
 * code, the author downloads a Word file with the QR code already in the
 * footer, and uploading the signed scan registers it.
 */
class InternalDocuments
{
    public function __construct(
        private readonly PdtsReferenceNumberGenerator $references,
        private readonly QrCodePng $qr,
        private readonly DocxTemplateFiller $filler,
    ) {}

    /**
     * @param  array{title: string, subject: string, description?: ?string, document_type_id: int, to: string, from: string, cc?: ?string, office_reference?: ?string, date: string, custom?: array<string, string>}  $data
     */
    public function createDraft(array $data, User $author, PdtsDocumentTemplate $template, PdtsOffice $office): PdtsDocument
    {
        if (! $template->is_active) {
            throw new RuntimeException('This template is no longer in use.');
        }

        // Prepared for someone else: they sign it; the preparer keeps custody.
        $signatoryId = (int) ($data['signatory_id'] ?? $author->getKey());
        $onBehalf = $signatoryId !== (int) $author->getKey();
        if ($onBehalf && ! app(PreparedDocuments::class)->canPrepareFor($author, $signatoryId)) {
            throw new RuntimeException('You are not authorised to prepare documents for that officer.');
        }

        return DB::transaction(function () use ($data, $author, $template, $office, $signatoryId, $onBehalf): PdtsDocument {
            $document = PdtsDocument::create([
                'signatory_id' => $signatoryId,
                'signature_status' => $onBehalf ? PdtsDocument::SIGNATURE_WITH_PREPARER : null,
                'reply_to_id' => $data['reply_to_id'] ?? null,
                ...$this->distributionFields($data),
                'reference_number' => $this->references->next(),
                'title' => $data['title'],
                'subject' => $data['subject'],
                'description' => $data['description'] ?? null,
                'document_type_id' => $data['document_type_id'],
                'originating_directorate' => $office->owningDirectorate()?->name,
                'originating_department' => $office->department?->name,
                'originating_office' => $office->name,
                'registered_by' => $author->id,
                'current_custodian_id' => $author->id,
                'status' => DocumentStatus::Draft->value,
                'registered_at' => now(),
                'origin' => 'internal',
                'template_id' => $template->id,
                'template_data' => [
                    'to' => $data['to'],
                    'from' => $data['from'],
                    'cc' => $data['cc'] ?? '',
                    'office_reference' => $data['office_reference'] ?? '',
                    'date' => $data['date'],
                    'office_id' => $office->id,
                    // The template's own placeholders, e.g. ['amount' => 'K 12,000'].
                    'custom' => $data['custom'] ?? [],
                ],
            ]);

            $document->auditLogs()->create([
                'actor_id' => $author->id,
                'action' => 'draft_created',
                'details' => array_filter([
                    'reference_number' => $document->reference_number,
                    'template_id' => $template->id,
                    'template_name' => $template->name,
                    'prepared_for' => $onBehalf ? $signatoryId : null,
                ]),
                'occurred_at' => now(),
            ]);

            if (! empty($data['files'])) {
                $templateData = $document->template_data;
                $templateData['custom'] = $this->keepFieldFiles($document, $data['files'], $author, (array) ($templateData['custom'] ?? []));
                $document->update(['template_data' => $templateData]);
            }

            app(PreparedDocuments::class)->recordVersion($document, $author, 'created');

            return $document;
        }, attempts: 3);
    }

    /**
     * Who the document is for in PDTS, and who gets a copy. Only active people.
     *
     * @return array{recipient_user_id: ?int, cc_user_ids: ?list<int>}
     */
    private function distributionFields(array $data): array
    {
        $active = fn (array $ids) => User::query()->whereIn('id', $ids)->where('is_banned', false)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $recipient = isset($data['recipient_user_id']) && $data['recipient_user_id'] ? ($active([(int) $data['recipient_user_id']])[0] ?? null) : null;
        $copies = array_values(array_diff($active(array_map('intval', (array) ($data['cc_user_ids'] ?? []))), [$recipient]));

        return [
            'recipient_user_id' => $recipient,
            'cc_user_ids' => $copies ?: null,
        ];
    }

    /**
     * Saves changes to a draft by its preparer and keeps the new version. A
     * draft returned for correction goes back to the preparer's hands, to be
     * sent for signature again.
     *
     * @param  array{title: string, subject: string, description?: ?string, document_type_id: int, to: string, from: string, cc?: ?string, office_reference?: ?string, date: string, custom?: array<string, string>, signatory_id?: ?int}  $data
     */
    public function updateDraft(PdtsDocument $document, array $data, User $user, PdtsOffice $office): PdtsDocument
    {
        $prepared = app(PreparedDocuments::class);
        if (! $prepared->canEdit($document, $user)) {
            throw new RuntimeException('This draft cannot be edited now.');
        }

        $signatoryId = (int) ($data['signatory_id'] ?? $document->signatoryId());
        if (! $prepared->canPrepareFor($user, $signatoryId)) {
            throw new RuntimeException('You are not authorised to prepare documents for that officer.');
        }
        $onBehalf = $signatoryId !== (int) $document->registered_by;
        $wasReturned = $document->signature_status === PdtsDocument::SIGNATURE_RETURNED;

        return DB::transaction(function () use ($document, $data, $user, $office, $signatoryId, $onBehalf, $wasReturned, $prepared): PdtsDocument {
            $document->update([
                'title' => $data['title'],
                'subject' => $data['subject'],
                'description' => $data['description'] ?? null,
                'document_type_id' => $data['document_type_id'],
                'originating_directorate' => $office->owningDirectorate()?->name,
                'originating_department' => $office->department?->name,
                'originating_office' => $office->name,
                'signatory_id' => $signatoryId,
                'signature_status' => $onBehalf ? PdtsDocument::SIGNATURE_WITH_PREPARER : null,
                ...$this->distributionFields($data),
                'template_data' => [
                    'to' => $data['to'],
                    'from' => $data['from'],
                    'cc' => $data['cc'] ?? '',
                    'office_reference' => $data['office_reference'] ?? '',
                    'date' => $data['date'],
                    'office_id' => $office->id,
                    'custom' => empty($data['files']) ? ($data['custom'] ?? []) : $this->keepFieldFiles($document, $data['files'], $user, $data['custom'] ?? []),
                ],
            ]);

            $version = $prepared->recordVersion($document->refresh(), $user, $wasReturned ? 'corrected' : 'edited');
            $prepared->log($document, $user, 'draft_edited', [
                'version' => $version->version,
                'changed' => $version->changed_fields ?: [],
            ]);

            return $document;
        });
    }

    /**
     * The document's file, generated afresh each time: a filled Word file, or
     * for a PDF template the PDF with this document's QR code on it.
     *
     * @return array{filename: string, contents: string, mime: string}
     */
    public function wordFile(PdtsDocument $document): array
    {
        $template = $document->template;

        if (! $document->isInternal() || ! $template?->storage_path) {
            throw new RuntimeException('This document was not created from a template.');
        }

        $subject = Str::of($document->subject)->replaceMatches('/[^A-Za-z0-9 ._-]+/', '')->squish()->limit(60, '');
        $basename = trim($document->reference_number.' '.$subject);

        if ($template->isPdf()) {
            return [
                'filename' => $basename.'.pdf',
                'contents' => app(TemplateSetup::class)->stamp(
                    Storage::disk('private')->get($template->storage_path),
                    $template->placement(),
                    $document->scanUrl(),
                    $document->reference_number,
                ),
                'mime' => 'application/pdf',
            ];
        }

        return [
            'filename' => $basename.'.docx',
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'contents' => $this->filledWord($document),
        ];
    }

    /**
     * The draft exactly as it will be signed, as a PDF made by the same
     * converter, only without the signature. Kept until the draft changes, so
     * reading it again is instant.
     */
    public function previewPdf(PdtsDocument $document): string
    {
        // Named after what goes into the document (the Word file itself differs on every build).
        $key = hash('sha256', json_encode([
            $document->getKey(),
            $document->template?->storage_path,
            $this->placeholderValues($document),
            $this->richValues($document),
            $this->tableValues($document),
            $document->scan_token,
        ]));
        $path = 'pdts/previews/'.$key.'.pdf';
        $disk = Storage::disk('private');

        if (! $disk->exists($path)) {
            $disk->put($path, app(PdfConverter::class)->convert($this->filledWord($document)));
        }

        return $disk->get($path);
    }

    /**
     * A copy of a filled Word file for the in-page preview. The preview library
     * stops at Word's alternative-content blocks (used for the floating QR box),
     * so each is replaced by its plain drawing. The real file is not changed.
     */
    public function forBrowserPreview(string $docx): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pdts');
        file_put_contents($path, $docx);

        try {
            $parts = DocxPackage::read($path);
        } finally {
            @unlink($path);
        }

        foreach ($parts as $name => $xml) {
            if (str_ends_with($name, '.xml') && str_contains((string) $xml, 'AlternateContent')) {
                $parts[$name] = preg_replace('#<mc:AlternateContent>\s*<mc:Choice[^>]*>(.*?)</mc:Choice>.*?</mc:AlternateContent>#s', '$1', (string) $xml);
            }
        }

        return DocxPackage::write($parts);
    }

    /**
     * A template filled with answers typed on its preview form, as the PDF a
     * document from it would be, without saving anything: no draft, no
     * reference number (a sample one is shown), and a sample signature where
     * the template is signed. Null when this server cannot make PDFs.
     *
     * @param  array{to?: string, from?: string, cc?: string, office_reference?: string, date?: string, subject?: string, title?: string, custom?: array<string, string>, signatory_id?: ?int, office_id?: ?int}  $answers
     */
    public function previewFromAnswers(PdtsDocumentTemplate $template, array $answers, User $author): ?string
    {
        $converter = app(PdfConverter::class);
        if ($template->isPdf() || ! $template->storage_path || ! $converter->available()) {
            return null;
        }

        $office = isset($answers['office_id']) ? PdtsOffice::with(['department.directorate', 'directorate'])->find((int) $answers['office_id']) : null;
        $signatory = isset($answers['signatory_id']) ? User::find((int) $answers['signatory_id']) : null;

        // An unsaved document holding the answers: filled exactly as a real one is.
        $document = (new PdtsDocument)->forceFill([
            'reference_number' => 'OMTS-2026-0000',
            'scan_token' => 'SAMPLE',
            'title' => (string) (($answers['title'] ?? '') ?: ($answers['subject'] ?? '')),
            'subject' => (string) ($answers['subject'] ?? ''),
            'originating_directorate' => $office?->owningDirectorate()?->name,
            'originating_department' => $office?->department?->name,
            'originating_office' => $office?->name,
            'template_data' => [
                'to' => (string) ($answers['to'] ?? ''),
                'from' => (string) ($answers['from'] ?? ''),
                'cc' => (string) ($answers['cc'] ?? ''),
                'office_reference' => (string) ($answers['office_reference'] ?? ''),
                'date' => ($answers['date'] ?? '') ?: now()->toDateString(),
                'custom' => (array) ($answers['custom'] ?? []),
            ],
        ]);
        $document->setRelation('template', $template);
        $document->setRelation('signatory', $signatory ?? $author);
        $document->setRelation('registrar', $author);

        $signature = $template->supportsElectronicSignature()
            ? ['image' => TemplateSetup::sampleSignature(), 'extension' => 'png', 'width_mm' => 36.0, 'height_mm' => 14.0]
            : null;

        return $converter->convert($this->filledWord($document, $signature));
    }

    /**
     * The filled Word file; with a signature, the author's signature is put
     * on the template's signature spot.
     *
     * @param  array{image: string, extension: string, width_mm: float, height_mm: float}|null  $signature
     */
    public function filledWord(PdtsDocument $document, ?array $signature = null): string
    {
        $template = $document->template;

        return $this->filler->fill(
            Storage::disk('private')->path($template->storage_path),
            $this->placeholderValues($document),
            $this->qr->make($document->scanUrl()),
            $template->qr_size_mm ?? 20.0,
            $signature,
            $this->richValues($document),
            $this->tableValues($document),
            $this->omittedFields($document),
        );
    }

    /**
     * Table answers: name => [columns, rows]. Stored as JSON: a list of rows,
     * each a list of cells.
     *
     * @return array<string, array{columns: list<string>, rows: list<list<string>>}>
     */
    public function tableValues(PdtsDocument $document): array
    {
        $template = $document->template;
        $tables = [];
        foreach ((array) (($document->template_data ?? [])['custom'] ?? []) as $name => $value) {
            $setting = is_string($name) ? $template?->fieldSetting($name) : null;
            if ($setting && $setting['type'] === 'table' && ! $setting['hidden']) {
                $tables[$name] = ['columns' => $setting['columns'], 'rows' => self::tableRows((string) $value)];
            }
        }

        return $tables;
    }

    /** @return list<list<string>> */
    public static function tableRows(string $json): array
    {
        $rows = json_decode($json, true);

        return is_array($rows)
            ? array_values(array_map(fn ($row) => array_values(array_map('strval', (array) $row)), array_filter($rows, 'is_array')))
            : [];
    }

    /**
     * Formatted text answers (name => HTML), filled as real formatting.
     *
     * @return array<string, string>
     */
    public function richValues(PdtsDocument $document): array
    {
        $template = $document->template;
        $rich = [];
        foreach ((array) (($document->template_data ?? [])['custom'] ?? []) as $name => $value) {
            $setting = is_string($name) ? $template?->fieldSetting($name) : null;
            if ($setting && $setting['type'] === 'rich' && ! $setting['hidden']) {
                $rich[$name] = (string) $value;
            }
        }

        return $rich;
    }

    /**
     * Keeps the files for the template's attachment questions with the
     * document (not as its main file), and puts each one's id in the answer.
     *
     * @param  array<string, UploadedFile>  $files
     * @param  array<string, string>  $custom
     * @return array<string, string>
     */
    private function keepFieldFiles(PdtsDocument $document, array $files, User $user, array $custom): array
    {
        foreach ($files as $name => $file) {
            $path = $file->store('pdts/documents', 'private');
            if (! $path) {
                throw new RuntimeException('The attached file could not be stored.');
            }

            $attachment = $document->attachments()->create([
                'uploaded_by' => $user->id,
                'storage_path' => $path,
                'original_filename' => Str::limit(basename($file->getClientOriginalName()), 250, ''),
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'file_size' => $file->getSize(),
                'is_primary' => false,
            ]);
            $custom[$name] = (string) $attachment->id;
        }

        return $custom;
    }

    /**
     * Why this draft may not be signed on paper, under the paper signing
     * setting (pdts.signatures.paper_signing); null when it may. A template
     * without a signature spot can only be signed on paper, so it always may.
     */
    public function paperSigningBlocker(PdtsDocument $document): ?string
    {
        if ($document->template?->isUnsigned()) {
            return 'This document is not signed, so it is issued in PDTS, not printed and scanned.';
        }

        $mode = DocumentOrigins::paperSigningMode();

        if ($mode === 'allowed' || ! $document->template?->supportsElectronicSignature()) {
            return null;
        }

        if ($mode === 'fallback') {
            $signatory = User::query()->find($document->signatoryId());

            return $signatory && $signatory->canSignElectronically()
                ? ($signatory->name ?? 'The signatory').' has a verified signature, so this document is signed in PDTS, not on paper.'
                : null;
        }

        return 'Signing on paper is switched off. This document is signed in PDTS.';
    }

    /**
     * Why this person cannot issue this unsigned draft now, or null when they
     * can. The author issues it; when prepared for an officer, the officer
     * does, once it has been sent to them (no implicit authority).
     */
    public function issueBlocker(PdtsDocument $document, User $user): ?string
    {
        return match (true) {
            ! $document->isDraft() => 'This document has already been registered.',
            ! $document->template?->isUnsigned() => 'This document is signed, not issued without a signature.',
            $document->signature_status === PreparedDocuments::DISCARDED => 'This draft was discarded.',
            $document->signatoryId() !== (int) $user->getKey() => 'Only '.($document->isPreparedOnBehalf() ? $document->signatory?->name : 'the author').' can issue this document.',
            $document->isPreparedOnBehalf() && $document->signature_status !== PdtsDocument::SIGNATURE_AWAITING => 'This draft has not been sent to you yet.',
            default => null,
        };
    }

    /**
     * Issues a draft from a template marked as not signed: the filled document
     * (a PDF when this server can make one, otherwise the Word file) becomes
     * the registered document, with its fingerprint. Custody does not change.
     */
    public function issueUnsigned(PdtsDocument $document, User $user): PdtsDocument
    {
        $document->loadMissing('template', 'registrar', 'signatory');

        if ($reason = $this->issueBlocker($document, $user)) {
            throw new RuntimeException($reason);
        }

        $converter = app(PdfConverter::class);
        $word = $this->filledWord($document);
        [$contents, $extension, $mime] = $converter->available()
            ? [$converter->convert($word), 'pdf', 'application/pdf']
            : [$word, 'docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        $hash = hash('sha256', $contents);
        $path = 'pdts/documents/'.Str::random(40).'.'.$extension;
        Storage::disk('private')->put($path, $contents);

        try {
            $issued = DB::transaction(function () use ($document, $user, $contents, $extension, $mime, $hash, $path): PdtsDocument {
                $locked = PdtsDocument::query()->lockForUpdate()->findOrFail($document->getKey());
                if ($reason = $this->issueBlocker($locked, $user)) {
                    throw new RuntimeException($reason);
                }

                $subject = Str::of($locked->subject)->replaceMatches('/[^A-Za-z0-9 ._-]+/', '')->squish()->limit(60, '');
                $locked->attachments()->create([
                    'uploaded_by' => $user->id,
                    'storage_path' => $path,
                    'original_filename' => trim($locked->reference_number.' '.$subject).'.'.$extension,
                    'mime_type' => $mime,
                    'file_size' => strlen($contents),
                    'is_primary' => true,
                ]);

                $locked->update([
                    'status' => DocumentStatus::Registered->value,
                    'registered_at' => now(),
                    'signature_status' => PdtsDocument::SIGNATURE_NOT_REQUIRED,
                ]);

                $locked->auditLogs()->create([
                    'actor_id' => $user->id,
                    'action' => 'registered',
                    'details' => array_filter([
                        'reference_number' => $locked->reference_number,
                        'initial_custodian_id' => $locked->current_custodian_id,
                        'from_draft' => true,
                        'issued_without_signature' => true,
                        'file_sha256' => $hash,
                        'prepared_by' => (int) $locked->registered_by !== (int) $user->id ? $locked->registered_by : null,
                    ]),
                    'occurred_at' => now(),
                ]);

                return $locked;
            });
        } catch (Throwable $exception) {
            Storage::disk('private')->delete($path);

            throw $exception;
        }

        if ($issued->isPreparedOnBehalf()) {
            app(PreparedDocuments::class)->tell($issued->registrar, $issued, 'issued', $user);
        }
        app(DocumentDistribution::class)->onRegistered($issued);

        return $issued;
    }

    /**
     * Registers a draft once the signed paper has been scanned.
     */
    public function attachSignedScan(PdtsDocument $document, User $user, UploadedFile $file): PdtsDocument
    {
        if ($blocker = $this->paperSigningBlocker($document)) {
            throw new RuntimeException($blocker);
        }

        $storagePath = $file->store('pdts/documents', 'private');

        if (! $storagePath) {
            throw new RuntimeException('The scanned document could not be stored.');
        }

        try {
            $registered = DB::transaction(function () use ($document, $user, $file, $storagePath): PdtsDocument {
                $locked = PdtsDocument::query()->lockForUpdate()->findOrFail($document->getKey());

                if (! $locked->isDraft()) {
                    throw new RuntimeException('This document has already been registered.');
                }

                if ((int) $locked->registered_by !== (int) $user->getKey()
                    && (int) $locked->current_custodian_id !== (int) $user->getKey()) {
                    throw new RuntimeException('Only the author can upload the signed scan.');
                }

                $locked->attachments()->create([
                    'uploaded_by' => $user->id,
                    'storage_path' => $storagePath,
                    'original_filename' => basename($file->getClientOriginalName()),
                    'mime_type' => $file->getMimeType() ?: $file->getClientMimeType(),
                    'file_size' => $file->getSize(),
                    'is_primary' => true,
                ]);

                $locked->update([
                    'status' => DocumentStatus::Registered->value,
                    'registered_at' => now(),
                ]);

                $locked->auditLogs()->create([
                    'actor_id' => $user->id,
                    'action' => 'registered',
                    'details' => [
                        'reference_number' => $locked->reference_number,
                        'initial_custodian_id' => $locked->current_custodian_id,
                        'from_draft' => true,
                    ],
                    'occurred_at' => now(),
                ]);

                return $locked;
            });
        } catch (Throwable $exception) {
            Storage::disk('private')->delete($storagePath);

            throw $exception;
        }

        // The person it is for, and the people copied, are told and can open it.
        app(DocumentDistribution::class)->onRegistered($registered);

        return $registered;
    }

    /**
     * @return array<string, string>
     */
    public function placeholderValues(PdtsDocument $document): array
    {
        $data = $document->template_data ?? [];
        $date = isset($data['date']) ? Carbon::parse($data['date'])->format('j F Y') : '';

        $template = $document->template;

        // The template's own fields first, so they can never replace what PDTS fills itself.
        $custom = [];
        foreach ((array) ($data['custom'] ?? []) as $name => $value) {
            if (is_string($name) && ! array_key_exists($name, PdtsDocumentTemplate::PLACEHOLDERS)) {
                $setting = $template?->fieldSetting($name);
                $value = (string) $value;
                if ($setting && $setting['type'] === 'date' && trim($value) !== '' && strtotime($value) !== false) {
                    $value = Carbon::parse($value)->format('j F Y');
                }
                $value = match ($setting['type'] ?? null) {
                    // People chosen in PDTS: "Name, Job title", one per line.
                    'person', 'people' => User::withTrashed()->whereIn('id', array_filter(array_map('intval', explode(',', $value))))->get(['id', 'name', 'job_title'])
                        ->sortBy(fn ($u) => array_search($u->id, array_map('intval', explode(',', $value))))
                        ->map(fn ($u) => $u->name.($u->job_title ? ', '.PositionText::tidy($u->job_title) : ''))->implode("\n"),
                    // The file kept with the document, by name.
                    'attachment' => ctype_digit(trim($value)) && ($file = $document->attachments()->find((int) $value)) ? 'Attached: '.$file->original_filename : '',
                    // Formatted text is filled with its formatting; this plain copy is for the preview key.
                    'rich' => RichText::plain($value),
                    // A table is filled as a table; this plain copy is for the preview key.
                    'table' => implode("\n", array_map(fn ($row) => implode(' | ', $row), self::tableRows($value))),
                    default => $value,
                };
                // Hidden: left empty, to be filled in by hand. Left empty by the author: not on
                // the document at all (omittedFields; the filler takes the line out).
                $custom[$name] = (bool) ($setting['hidden'] ?? false) ? '' : $value;
            }
        }

        $values = $custom + [
            'reference' => $document->reference_number,
            'title' => (string) $document->title,
            'subject' => (string) $document->subject,
            'to' => (string) ($data['to'] ?? ''),
            'from' => (string) ($data['from'] ?? ''),
            'cc' => (string) ($data['cc'] ?? ''),
            'office_reference' => (string) ($data['office_reference'] ?? ''),
            'date' => $date,
            // The officer the document is from: the signatory, not the secretary who typed it.
            'author' => $signer = (string) (($document->signatory ?? $document->registrar)?->name ?? ''),
            'author_upper' => mb_strtoupper($signer),
            // In ordinary capitals, as the job titles picked on the form are (PositionText).
            'author_job_title' => PositionText::tidy(($document->signatory ?? $document->registrar)?->job_title),
            'directorate' => (string) ($document->originating_directorate ?? ''),
            'department' => (string) ($document->originating_department ?? ''),
            'office' => (string) ($document->originating_office ?? ''),
        ];

        // Heading lines the administrator hid are left empty.
        foreach (PdtsDocumentTemplate::HEADING_FIELDS as $name) {
            if ($template?->fieldSetting($name)['hidden'] ?? false) {
                $values[$name] = '';
            }
        }

        return $values;
    }

    /**
     * The template's own fields the author left empty: they are left out of
     * the document, with their label (name => label on the form). A hidden
     * field is not among them: it stays, empty, to be filled in by hand.
     *
     * @return array<string, string>
     */
    public function omittedFields(PdtsDocument $document): array
    {
        $template = $document->template;
        if (! $template) {
            return [];
        }

        $values = $this->placeholderValues($document);
        $omitted = [];
        foreach ($template->customFields() as $name => $label) {
            if (! $template->fieldSetting($name)['hidden'] && trim((string) ($values[$name] ?? '')) === '') {
                $omitted[$name] = (string) $label;
            }
        }
        // What PDTS fills from the office ("Department: ..."): left out too when there is nothing to put.
        foreach (['directorate', 'department', 'office'] as $name) {
            if ($template->usesField($name) && trim((string) ($values[$name] ?? '')) === '') {
                $omitted[$name] = ucfirst($name);
            }
        }

        return $omitted;
    }
}
