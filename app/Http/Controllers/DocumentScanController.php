<?php

namespace App\Http\Controllers;

use App\Models\PdtsDocument;
use App\Services\Documents\CustodyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a document's scanned copy inline so a recipient can check it against
 * the paper. Unlike the registration download, access follows custody: people
 * involved in the document can see it without holding documents.view.
 */
class DocumentScanController extends Controller
{
    public function __invoke(Request $request, PdtsDocument $document, CustodyService $custody): StreamedResponse
    {
        abort_unless($custody->canViewHistory($document, $request->user()), 403);

        $scan = $document->primaryAttachment;

        abort_unless($scan && Storage::disk('private')->exists($scan->storage_path), 404, 'This document has no scanned copy.');

        return Storage::disk('private')->response(
            $scan->storage_path,
            $scan->original_filename,
            ['Content-Type' => $scan->mime_type],
            'inline',
        );
    }
}
