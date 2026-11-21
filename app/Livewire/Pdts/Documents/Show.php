<?php

namespace App\Livewire\Pdts\Documents;

use App\Http\Controllers\CoverSheetController;
use App\Models\PdtsDocument;
use App\Models\User;
use App\Services\Documents\CustodyService;
use App\Services\Pdts\InternalDocuments;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

class Show extends Component
{
    use WithFileUploads;

    public PdtsDocument $document;

    // The signed scan that registers an internal draft.
    public $signedScan;

    public function uploadSignedScan(InternalDocuments $internal): void
    {
        $this->validate(
            ['signedScan' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg,tif,tiff', 'max:20480']],
            ['signedScan.required' => 'Choose the scan of the signed document.'],
        );

        try {
            $internal->attachSignedScan($this->document, Auth::user(), $this->signedScan);
        } catch (RuntimeException $exception) {
            $this->addError('signedScan', $exception->getMessage());

            return;
        }

        $this->reset('signedScan');
        session()->flash('success', 'Signed scan uploaded. The document is registered and can now be sent.');
    }

    public function mount(PdtsDocument $document): void
    {
        $this->document = $document;
    }

    #[On('handover-changed')]
    public function refreshDocument(): void
    {
        // Re-renders after a forward from the embedded form.
    }

    public function render()
    {
        $this->document->refresh()->load([
            'documentType',
            'registrar',
            'currentCustodian',
            'attachments.uploader',
            'auditLogs.actor',
            'pendingMovement.recipient',
            'latestReceipt',
        ]);

        $user = Auth::user();
        $custody = app(CustodyService::class);

        // Names of the people each handover was sent to, for the activity list.
        $recipientIds = $this->document->auditLogs->pluck('details.recipient_id')->filter()->unique();
        $people = $recipientIds->isEmpty()
            ? collect()
            : User::withTrashed()->whereKey($recipientIds)->pluck('name', 'id');

        return view('livewire.pdts.documents.show', [
            'canViewHistory' => $custody->canViewHistory($this->document, $user),
            'canForward' => $user->can('documents.forward') && $this->document->current_custodian_id === $user->id && ! $this->document->isDraft(),
            'canPrintCoverSheet' => ! $this->document->isDraft() && CoverSheetController::canPrint($this->document, $user),
            'coverSheetPrinted' => $this->document->auditLogs->contains('action', 'cover_sheet_printed'),
            'canUploadScan' => $this->document->isDraft()
                && in_array((int) $user->id, [(int) $this->document->registered_by, (int) $this->document->current_custodian_id], true),
            'canDownloadWord' => $this->document->isInternal() && $this->document->template_id
                && ((int) $this->document->registered_by === (int) $user->id || $custody->canViewHistory($this->document, $user)),
            'heldSince' => $this->document->latestReceipt?->acknowledged_at ?? $this->document->registered_at,
            'people' => $people,
        ])->layout('layouts.master', ['title' => $this->document->reference_number]);
    }
}
