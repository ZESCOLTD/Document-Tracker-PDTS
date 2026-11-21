<?php

namespace App\Http\Controllers;

use App\Models\PdtsDocumentTemplate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lets administrators download an uploaded template to check or edit it.
 */
class DocumentTemplateDownloadController extends Controller
{
    public function __invoke(PdtsDocumentTemplate $template): StreamedResponse
    {
        abort_if($template->is_builtin || ! $template->storage_path, 404);
        abort_unless(Storage::disk('private')->exists($template->storage_path), 404);

        return Storage::disk('private')->download(
            $template->storage_path,
            $template->original_filename ?: 'template.docx',
        );
    }
}
