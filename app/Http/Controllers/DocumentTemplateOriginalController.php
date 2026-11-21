<?php

namespace App\Http\Controllers;

use App\Models\PdtsDocumentTemplate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The template exactly as it was uploaded, for the QR placement preview.
 */
class DocumentTemplateOriginalController extends Controller
{
    public function __invoke(PdtsDocumentTemplate $template): StreamedResponse
    {
        $path = $template->original_path ?: $template->storage_path;
        abort_unless($path && Storage::disk('private')->exists($path), 404);

        return Storage::disk('private')->response($path, $template->original_filename ?: 'template.'.$template->kind, [
            'Content-Type' => $template->isPdf()
                ? 'application/pdf'
                : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
