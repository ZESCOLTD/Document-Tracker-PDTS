<?php

namespace App\Http\Controllers;

use App\Models\PdtsDocument;
use App\Services\Pdts\Templates\QrCodePng;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The A4 cover sheet stapled to a paper document, mainly for external
 * documents, which arrive without a QR code. The confidential version shows
 * only the QR code and reference. Every print is recorded in the history.
 */
class CoverSheetController extends Controller
{
    public function __invoke(Request $request, PdtsDocument $document, QrCodePng $qr): View
    {
        $user = $request->user();

        abort_unless(self::canPrint($document, $user), 403);
        abort_if($document->isDraft(), 404, 'Upload the signed scan before printing a cover sheet.');

        $confidential = $request->boolean('confidential');
        $previousPrints = $document->auditLogs()->where('action', 'cover_sheet_printed')->count();

        $document->auditLogs()->create([
            'actor_id' => $user->getKey(),
            'action' => 'cover_sheet_printed',
            'details' => [
                'confidential' => $confidential,
                'reprint' => $previousPrints > 0,
            ],
            'occurred_at' => now(),
        ]);

        $document->loadMissing(['documentType', 'registrar']);

        return view('pdts.cover-sheet', [
            'document' => $document,
            'confidential' => $confidential,
            'reprint' => $previousPrints > 0,
            'qrDataUri' => 'data:image/png;base64,'.base64_encode($qr->make($document->scanUrl(), 12)),
            'logoDataUri' => 'data:image/png;base64,'.base64_encode((string) file_get_contents(resource_path('images/pdts/zesco-memo-logo.png'))),
        ]);
    }

    /**
     * The person who registered the document and its current holder can print
     * or reprint its cover sheet.
     */
    public static function canPrint(PdtsDocument $document, $user): bool
    {
        return (int) $document->registered_by === (int) $user->getKey()
            || (int) $document->current_custodian_id === (int) $user->getKey();
    }
}
