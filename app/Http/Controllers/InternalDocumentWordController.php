<?php

namespace App\Http\Controllers;

use App\Models\PdtsDocument;
use App\Services\Documents\CustodyService;
use App\Services\Pdts\InternalDocuments;
use App\Services\Pdts\Signatures\PdfConverter;
use App\Services\Pdts\Signatures\PreparedDocuments;
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
            (int) $document->registered_by === (int) $user->getKey()
                || $document->signatoryId() === (int) $user->getKey() // the officer it was prepared for
                || $custody->canViewHistory($document, $user),
            403,
        );
        abort_unless($document->isInternal() && $document->template_id, 404, 'This document has no template file.');
        // A document signed in PDTS is its signed PDF; no unsigned copies are made of it.
        abort_if($document->electronicSignature()->exists(), 403, 'This document was signed in PDTS. Download the signed PDF instead.');
        // A discarded draft is not printed and used after all.
        abort_if($document->signature_status === PreparedDocuments::DISCARDED, 403, 'This draft was discarded.');

        // Reading before signing: the exact PDF it will become, without the signature.
        if ($request->boolean('pdf')) {
            abort_unless($document->isDraft() && ! $document->template?->isPdf(), 404);
            abort_unless(app(PdfConverter::class)->available(), 404);

            return response($internal->previewPdf($document->loadMissing(['template', 'registrar', 'signatory'])), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$document->reference_number.' (preview).pdf"',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        $file = $internal->wordFile($document->loadMissing(['template', 'registrar']));

        // For reading on the page: the browser's Word renderer cannot read Word's
        // floating text boxes, so the QR box is unwrapped in this copy only.
        if ($request->boolean('preview') && str_ends_with($file['filename'], '.docx')) {
            $file['contents'] = $internal->forBrowserPreview($file['contents']);
        }

        return response($file['contents'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'attachment; filename="'.addcslashes($file['filename'], '"\\').'"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
