<?php

namespace App\Services\Pdts;

use App\Models\PdtsDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RegisterPdtsDocument
{
    public function __construct(private readonly PdtsReferenceNumberGenerator $references) {}

    /**
     * @param array{title: string, subject: string, description?: ?string, document_type_id: int, originating_directorate?: ?string, originating_department?: ?string, originating_office: string} $data
     */
    public function handle(array $data, User $registrar, UploadedFile $file): PdtsDocument
    {
        $storagePath = $file->store('pdts/documents', 'private');

        if (! $storagePath) {
            throw new \RuntimeException('The scanned document could not be stored.');
        }

        try {
            return DB::transaction(function () use ($data, $registrar, $file, $storagePath): PdtsDocument {
                $document = PdtsDocument::create([
                    'reference_number' => $this->references->next(),
                    'title' => $data['title'],
                    'subject' => $data['subject'],
                    'description' => $data['description'] ?? null,
                    'document_type_id' => $data['document_type_id'],
                    'originating_directorate' => $data['originating_directorate'] ?? null,
                    'originating_department' => $data['originating_department'] ?? null,
                    'originating_office' => $data['originating_office'],
                    'registered_by' => $registrar->id,
                    'current_custodian_id' => $registrar->id,
                    'status' => 'registered',
                    'registered_at' => now(),
                ]);

                $document->attachments()->create([
                    'uploaded_by' => $registrar->id,
                    'storage_path' => $storagePath,
                    'original_filename' => basename($file->getClientOriginalName()),
                    'mime_type' => $file->getMimeType() ?: $file->getClientMimeType(),
                    'file_size' => $file->getSize(),
                    'is_primary' => true,
                ]);

                $document->auditLogs()->create([
                    'actor_id' => $registrar->id,
                    'action' => 'registered',
                    'details' => [
                        'reference_number' => $document->reference_number,
                        'initial_custodian_id' => $registrar->id,
                    ],
                    'occurred_at' => now(),
                ]);

                return $document->load(['documentType', 'attachments']);
            }, attempts: 3);
        } catch (Throwable $exception) {
            Storage::disk('private')->delete($storagePath);

            throw $exception;
        }
    }
}