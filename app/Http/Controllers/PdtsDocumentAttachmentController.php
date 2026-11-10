<?php

namespace App\Http\Controllers;

use App\Models\PdtsDocument;
use App\Models\PdtsDocumentAttachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PdtsDocumentAttachmentController extends Controller
{
    public function __invoke(PdtsDocument $document, PdtsDocumentAttachment $attachment): StreamedResponse
    {
        abort_unless(auth()->user()?->can('documents.view'), 403);
        abort_unless((int) $attachment->pdts_document_id === (int) $document->id, 404);
        abort_unless(Storage::disk('private')->exists($attachment->storage_path), 404);

        return Storage::disk('private')->download(
            $attachment->storage_path,
            $attachment->original_filename,
            ['Content-Type' => $attachment->mime_type],
        );
    }
}