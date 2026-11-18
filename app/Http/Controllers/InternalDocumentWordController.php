<?php

namespace App\Http\Controllers;

use App\Models\PdtsDocument;
use App\Services\Documents\CustodyService;
use App\Services\Pdts\InternalDocuments;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Downloads an internal document as a Word file with its QR code in the footer.
 */
class InternalDocumentWordController extends Controller
{
    public function __invoke(Request $request, PdtsDocument $document, InternalDocuments $internal, CustodyService $custody): Response
    {
        $user = $request->user();

        abort_unless(
            (int) $document->registered_by === (int) $user->getKey() || $custody->canViewHistory($document, $user),
            403,
        );
        abort_unless($document->isInternal() && $document->template_id, 404, 'This document has no Word file.');

        $file = $internal->wordFile($document->loadMissing(['template', 'registrar']));

        return response($file['contents'], 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename="'.addcslashes($file['filename'], '"\\').'"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
