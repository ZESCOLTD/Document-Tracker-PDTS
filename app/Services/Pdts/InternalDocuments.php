<?php

namespace App\Services\Pdts;

use App\Enums\DocumentStatus;
use App\Models\PdtsDocument;
use App\Models\PdtsDocumentTemplate;
use App\Models\PdtsOffice;
use App\Models\User;
use App\Services\Pdts\Templates\DocxTemplateFiller;
use App\Services\Pdts\Templates\QrCodePng;
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

        return DB::transaction(function () use ($data, $author, $template, $office): PdtsDocument {
            $document = PdtsDocument::create([
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
                    // The template's own placeholders, e.g. ['amount' => 'K 12,000'].
                    'custom' => $data['custom'] ?? [],
                ],
            ]);

            $document->auditLogs()->create([
                'actor_id' => $author->id,
                'action' => 'draft_created',
                'details' => [
                    'reference_number' => $document->reference_number,
                    'template_id' => $template->id,
                    'template_name' => $template->name,
                ],
                'occurred_at' => now(),
            ]);

            return $document;
        }, attempts: 3);
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

        $contents = $this->filler->fill(
            Storage::disk('private')->path($template->storage_path),
            $this->placeholderValues($document),
            $this->qr->make($document->scanUrl()),
            $template->qr_size_mm ?? 20.0,
        );

        return [
            'filename' => $basename.'.docx',
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'contents' => $contents,
        ];
    }

    /**
     * Registers a draft once the signed paper has been scanned.
     */
    public function attachSignedScan(PdtsDocument $document, User $user, UploadedFile $file): PdtsDocument
    {
        $storagePath = $file->store('pdts/documents', 'private');

        if (! $storagePath) {
            throw new RuntimeException('The scanned document could not be stored.');
        }

        try {
            return DB::transaction(function () use ($document, $user, $file, $storagePath): PdtsDocument {
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
    }

    /**
     * @return array<string, string>
     */
    public function placeholderValues(PdtsDocument $document): array
    {
        $data = $document->template_data ?? [];
        $date = isset($data['date']) ? Carbon::parse($data['date'])->format('j F Y') : '';

        // The template's own fields first, so they can never replace what PDTS fills itself.
        $custom = [];
        foreach ((array) ($data['custom'] ?? []) as $name => $value) {
            if (is_string($name) && ! array_key_exists($name, PdtsDocumentTemplate::PLACEHOLDERS)) {
                // Left empty: keep a line to write on by hand.
                $custom[$name] = trim((string) $value) !== '' ? (string) $value : '____________________';
            }
        }

        return $custom + [
            'reference' => $document->reference_number,
            'title' => (string) $document->title,
            'subject' => (string) $document->subject,
            'to' => (string) ($data['to'] ?? ''),
            'from' => (string) ($data['from'] ?? ''),
            'cc' => (string) ($data['cc'] ?? ''),
            'office_reference' => (string) ($data['office_reference'] ?? ''),
            'date' => $date,
            'author' => (string) ($document->registrar?->name ?? ''),
            'directorate' => (string) ($document->originating_directorate ?? ''),
            'department' => (string) ($document->originating_department ?? ''),
            'office' => (string) ($document->originating_office ?? ''),
        ];
    }
}
