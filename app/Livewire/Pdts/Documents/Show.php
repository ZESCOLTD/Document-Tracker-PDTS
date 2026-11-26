<?php

namespace App\Livewire\Pdts\Documents;

use App\Enums\MovementStatus;
use App\Http\Controllers\CoverSheetController;
use App\Models\PdtsDocument;
use App\Models\User;
use App\Services\Documents\CustodyService;
use App\Services\Documents\DocumentAccess;
use App\Services\Pdts\Closing\DocumentClosure;
use App\Services\Pdts\InternalDocuments;
use App\Services\Pdts\Signatures\ElectronicSigning;
use App\Services\Pdts\Signatures\PdfConverter;
use App\Services\Pdts\Signatures\PreparedDocuments;
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

    // The signatory's PIN or signature password, to sign the draft in PDTS.
    public string $signaturePin = '';

    // The signatory confirms they have reviewed the document before signing.
    public bool $reviewed = false;

    // Why the signatory returns the draft (required), or why it is discarded.
    public string $returnComment = '';

    public string $discardReason = '';

    public string $cancelReason = '';

    /** An administrator cancels a document issued in error; its history is kept. */
    public function cancelDocument(DocumentClosure $closure): void
    {
        $this->resetErrorBag('cancelReason');

        try {
            $closure->cancel($this->document, Auth::user(), $this->cancelReason);
        } catch (RuntimeException $exception) {
            $this->addError('cancelReason', $exception->getMessage());

            return;
        }

        $this->reset('cancelReason');
        session()->flash('success', 'The document was cancelled. Its history and files are kept.');
    }

    /**
     * Where the paper is for the person the document is for: received, on its
     * way, or not sent yet (then Forward suggests them).
     *
     * @return array{0: string, 1: string}|null icon and words
     */
    private function paperStatus(): ?array
    {
        $recipient = $this->document->recipientUser;
        if (! $recipient || $this->document->isDraft() || $this->document->status === 'cancelled') {
            return null;
        }

        $received = $this->document->movements()->where('recipient_id', $recipient->id)
            ->where('status', MovementStatus::Acknowledged->value)->latest('id')->first();
        $pending = $this->document->pendingMovement;

        return match (true) {
            (int) $this->document->current_custodian_id === (int) $recipient->id || $received !== null => ['fa-check-circle text-success', 'Paper received by '.$recipient->name.($received?->acknowledged_at ? ' on '.$received->acknowledged_at->format('j M Y') : '').'.'],
            // On its way to them: the bar under the title says so.
            $pending && (int) $pending->recipient_id === (int) $recipient->id => null,
            $this->document->status === 'completed' => null,
            // Not sent at all yet: the "Not sent" label and the Forward button already say so.
            ! $this->document->movements()->exists() => null,
            default => ['fa-share text-muted', 'Paper not sent to '.$recipient->name.' yet: use Forward.'],
        };
    }

    public function sendForSignature(PreparedDocuments $prepared): void
    {
        try {
            $prepared->sendForSignature($this->document, Auth::user());
        } catch (RuntimeException $exception) {
            $this->addError('workflow', $exception->getMessage());

            return;
        }

        session()->flash('success', 'Sent to '.$this->document->signatory?->name.' to review and sign. You will be told when it is signed or returned.');
    }

    public function returnForCorrection(PreparedDocuments $prepared): void
    {
        $this->validate(
            ['returnComment' => ['required', 'string', 'min:5', 'max:2000']],
            ['returnComment.required' => 'Say what needs to be corrected.', 'returnComment.min' => 'Say what needs to be corrected.'],
        );

        try {
            $prepared->returnForCorrection($this->document, Auth::user(), $this->returnComment);
        } catch (RuntimeException $exception) {
            $this->addError('returnComment', $exception->getMessage());

            return;
        }

        $this->reset('returnComment');
        session()->flash('success', 'Returned to '.$this->document->registrar?->name.' for correction.');
    }

    public function discardDraft(PreparedDocuments $prepared): void
    {
        try {
            $prepared->discard($this->document, Auth::user(), $this->discardReason);
        } catch (RuntimeException $exception) {
            $this->addError('workflow', $exception->getMessage());

            return;
        }

        $this->reset('discardReason');
        session()->flash('success', 'The draft was discarded. Its reference and history are kept on record.');
    }

    public function signElectronically(ElectronicSigning $signing): void
    {
        $this->validate(
            ['signaturePin' => ['required', 'string', 'max:64'], 'reviewed' => ['accepted']],
            [
                'signaturePin.required' => 'Enter your '.Auth::user()->signatureSecretName().'.',
                'reviewed.accepted' => 'Confirm that you have reviewed the document.',
            ],
        );

        try {
            $signing->sign($this->document, Auth::user(), $this->signaturePin, [
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ], $this->reviewed);
        } catch (RuntimeException $exception) {
            $this->reset('signaturePin');
            $this->addError('signaturePin', $exception->getMessage());

            return;
        }

        $this->reset('signaturePin', 'reviewed');
        session()->flash('success', $this->document->fresh()->isPreparedOnBehalf()
            ? 'Signed. The signed PDF is the official copy. '.$this->document->registrar?->name.' has been told and will send it on.'
            : 'Signed. The signed PDF is the document\'s official copy, and the document is registered and can now be sent.');
    }

    /** Issues a draft from a template marked as not signed. */
    public function issueUnsigned(InternalDocuments $internal): void
    {
        $this->resetErrorBag('reviewed');
        if (! $this->reviewed) {
            $this->addError('reviewed', 'Confirm that you have reviewed the document before issuing it.');

            return;
        }

        try {
            $internal->issueUnsigned($this->document, Auth::user());
        } catch (RuntimeException $exception) {
            $this->addError('reviewed', $exception->getMessage());

            return;
        }

        $this->reset('reviewed');
        session()->flash('success', 'Issued. The document is registered and can now be sent.');
    }

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
        // Only the people involved in it, and those who oversee everything.
        abort_unless(app(DocumentAccess::class)->canView($document, Auth::user()), 403, 'You are not involved in this document.');
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
            'electronicSignature.signer',
            'electronicSignature.attachment',
            'electronicSignature.signatureImage.reviewer',
            'template',
            'signatory',
            'versions.author',
            'recipientUser',
            'replyTo',
            'replies',
        ]);

        $signing = app(ElectronicSigning::class);
        $prepared = app(PreparedDocuments::class);
        $openDraft = $prepared->isOpenDraft($this->document);
        $isSignatory = $this->document->signatoryId() === (int) Auth::id();
        $isSignatoryDraft = $openDraft && $isSignatory;
        $onBehalf = $this->document->isPreparedOnBehalf();

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
            'canUploadScan' => $openDraft
                && in_array((int) $user->id, [(int) $this->document->registered_by, (int) $this->document->current_custodian_id], true)
                && app(InternalDocuments::class)->paperSigningBlocker($this->document) === null,
            // Once signed in PDTS, the signed PDF is the document; no new unsigned copies are made.
            'canDownloadWord' => $this->document->isInternal() && $this->document->template_id && ! $this->document->electronicSignature
                && $this->document->signature_status !== PreparedDocuments::DISCARDED
                && ((int) $this->document->registered_by === (int) $user->id || $isSignatory || $custody->canViewHistory($this->document, $user)),
            // Offered to the signatory of a draft whose template has a signature spot; on
            // someone's behalf, once it has been sent to them.
            'offerSigning' => $isSignatoryDraft && $this->document->template?->supportsElectronicSignature()
                && (! $onBehalf || $this->document->signature_status === PdtsDocument::SIGNATURE_AWAITING),
            'signingBlocker' => $isSignatoryDraft ? $signing->blocker($this->document, $user) : null,
            // From a template marked as not signed: issued in PDTS by the author (or the officer).
            'isUnsigned' => (bool) $this->document->template?->isUnsigned(),
            'offerIssue' => $isSignatoryDraft && app(InternalDocuments::class)->issueBlocker($this->document, $user) === null,
            'signature' => $this->document->electronicSignature,
            // Closing: cancelled, where it is filed.
            'canCancel' => app(DocumentClosure::class)->cancelBlocker($this->document, $user) === null,
            // Who it is for, and copies.
            'copies' => $this->document->copiedUsers(),
            // Where the paper is, for the person it is for (sent with Forward, like any handover).
            'paperStatus' => $this->paperStatus(),
            'canReply' => $this->document->isInternal() && ! $this->document->isDraft()
                && (int) $user->id !== $this->document->signatoryId() && $user->can('documents.register'),
            // Read the draft as the exact PDF it will become, when the server can make PDFs.
            'previewAsPdf' => $isSignatoryDraft && app(PdfConverter::class)->available(),
            'onBehalf' => $onBehalf,
            'isDiscarded' => $this->document->signature_status === PreparedDocuments::DISCARDED,
            'canEditDraft' => $prepared->canEdit($this->document, $user),
            'canSendForSignature' => $onBehalf && $openDraft
                && in_array($this->document->signature_status, [PdtsDocument::SIGNATURE_WITH_PREPARER, PdtsDocument::SIGNATURE_RETURNED], true)
                && $prepared->isActivePreparer($this->document, $user),
            'canReturn' => $onBehalf && $isSignatoryDraft && $this->document->signature_status === PdtsDocument::SIGNATURE_AWAITING,
            'canDiscard' => $openDraft && ($isSignatory || $prepared->isActivePreparer($this->document, $user)),
            'preparerLostAccess' => $onBehalf && $openDraft && (int) $this->document->registered_by === (int) $user->id
                && ! $prepared->isActivePreparer($this->document, $user),
            'canSeeVersions' => in_array((int) $user->id, [(int) $this->document->registered_by, $this->document->signatoryId()], true)
                || $custody->canViewHistory($this->document, $user),
            'heldSince' => $this->document->latestReceipt?->acknowledged_at ?? $this->document->registered_at,
            'people' => $people,
        ])->layout('layouts.master', ['title' => $this->document->reference_number]);
    }
}
