<?php

namespace App\Http\Controllers;

use App\Models\PdtsDocument;
use App\Services\Pdts\InternalDocuments;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An earlier version of a draft, as it was saved, for the preparer and the
 * signatory to compare. Only while the document is a draft: once signed, the
 * signed PDF is the document and no other copies are made.
 */
class DocumentVersionController extends Controller
{
    public function __invoke(Request $request, PdtsDocument $document, int $version, InternalDocuments $internal): Response
    {
        $user = $request->user();

        abort_unless(in_array((int) $user->getKey(), [(int) $document->registered_by, $document->signatoryId()], true), 403);
        abort_unless($document->isDraft() && $document->isInternal() && ! $document->template?->isPdf(), 404);

        $saved = $document->versions()->where('version', $version)->firstOrFail();

        // The document as it was in that version; the reference and QR code stay its own.
        $copy = $document->replicate();
        $copy->setRawAttributes(array_merge($document->getAttributes(), [
            'title' => $saved->data['title'] ?? $document->title,
            'subject' => $saved->data['subject'] ?? $document->subject,
            'signatory_id' => $saved->data['signatory_id'] ?? $document->signatory_id,
            'template_data' => json_encode($saved->data['template_data'] ?? []),
        ]));
        $copy->setRelation('template', $document->template);
        $copy->setRelation('registrar', $document->registrar);

        $file = $internal->wordFile($copy);
        $name = preg_replace('/\.docx$/', '', $file['filename']).' (version '.$saved->version.').docx';

        return response($file['contents'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'attachment; filename="'.addcslashes($name, '"\\').'"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
