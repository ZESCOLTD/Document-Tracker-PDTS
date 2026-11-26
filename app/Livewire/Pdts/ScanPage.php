<?php

namespace App\Livewire\Pdts;

use App\Enums\DocumentStatus;
use App\Models\DocumentMovement;
use App\Models\PdtsDocument;
use App\Models\User;
use App\Services\Documents\CustodyException;
use App\Services\Documents\CustodyService;
use App\Services\Documents\DocumentTracker;
use App\Services\Documents\FoundDocumentReports;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The page a printed QR code opens, made for phones. It shows where the
 * document is, then only the action that fits the person scanning: pickup for
 * the messenger, receipt for the recipient, forward / send back / complete for
 * the holder, cancel for the sender. People not involved see no details, only
 * a notice and a way to report that the paper reached them by mistake.
 *
 * Every action goes through CustodyService, the same as the other screens.
 */
class ScanPage extends Component
{
    #[Locked]
    public int $documentId;

    // Which form is open: decline, cancel, sendBack, complete or report.
    public ?string $form = null;

    public string $reason = '';

    public string $returnCategory = '';

    public string $completionAction = '';

    public string $note = '';

    public ?string $notice = null;

    public ?string $errorMessage = null;

    public function mount(string $token): void
    {
        $document = PdtsDocument::query()->where('scan_token', $token)->first();

        abort_unless($document, 404);

        $this->documentId = $document->id;

        // A guest who logs in from here comes back to this document afterwards.
        if (! Auth::check()) {
            session()->put('url.intended', $document->scanUrl());
        }
    }

    public static function requiresLogin(): bool
    {
        return (bool) config('pdts.scan_requires_login');
    }

    /**
     * Every action except the two guest confirmations needs a logged-in user.
     */
    private function userOrFail(): User
    {
        $user = Auth::user();
        abort_unless($user, 403);

        return $user;
    }

    #[On('handover-changed')]
    public function refreshPage(): void
    {
        // Re-renders after a forward from the embedded form.
    }

    public function openForm(string $form): void
    {
        abort_unless(in_array($form, ['decline', 'cancel', 'sendBack', 'complete', 'report'], true), 400);

        $this->resetValidation();
        $this->reset(['reason', 'returnCategory', 'completionAction', 'note', 'notice', 'errorMessage']);
        $this->form = $form;
    }

    public function closeForm(): void
    {
        $this->reset(['form', 'reason', 'returnCategory', 'completionAction', 'note']);
        $this->resetValidation();
    }

    public function confirmPickup(CustodyService $custody): void
    {
        // Scanning without a login is view-only: confirming is done in the app.
        $user = $this->userOrFail();

        $this->run(function () use ($custody, $user) {
            $custody->confirmPickup($this->pendingOrFail(), $user);

            return 'Pickup confirmed. Deliver the document to the recipient.';
        });
    }

    /** The messenger has handed the paper to the recipient; they then confirm receipt. */
    public function confirmDelivery(CustodyService $custody): void
    {
        $user = $this->userOrFail();

        $this->run(function () use ($custody, $user) {
            $movement = $custody->confirmDelivery($this->pendingOrFail(), $user);

            return 'Delivery confirmed. '.($movement->recipient?->name ?? 'The recipient').' now confirms receipt.';
        });
    }

    public function acknowledge(CustodyService $custody): void
    {
        $user = $this->userOrFail();

        $this->run(function () use ($custody, $user) {
            $custody->acknowledge($this->pendingOrFail(), $user);

            return 'Receipt confirmed. The document is now in your custody.';
        });
    }

    public function decline(CustodyService $custody): void
    {
        $this->validate(['reason' => ['required', 'string', 'max:1000']], ['reason.required' => 'Say why you are declining it.']);

        $this->run(function () use ($custody) {
            $custody->decline($this->pendingOrFail(), $this->userOrFail(), $this->reason);

            return 'You declined the document. The sender has been told.';
        });
    }

    public function cancelHandover(CustodyService $custody): void
    {
        $this->validate(['reason' => ['required', 'string', 'max:1000']], ['reason.required' => 'Say why you are cancelling the handover.']);

        $this->run(function () use ($custody) {
            $custody->cancel($this->pendingOrFail(), $this->userOrFail(), $this->reason);

            return 'The handover was cancelled. You can forward the document again.';
        });
    }

    public function sendBack(CustodyService $custody): void
    {
        $this->validate([
            'returnCategory' => ['required', Rule::in(array_keys(DocumentMovement::RETURN_CATEGORIES))],
            'reason' => ['required', 'string', 'max:1000'],
        ], ['returnCategory.required' => 'Choose why it is being sent back.', 'reason.required' => 'Say what needs correcting.']);

        $this->run(function () use ($custody) {
            $movement = $custody->sendBack($this->document(), $this->userOrFail(), $this->returnCategory, $this->reason);

            return "Sent back to {$movement->recipient->name} for correction. You stay responsible until they confirm receipt.";
        });
    }

    public function complete(CustodyService $custody): void
    {
        $this->validate([
            'completionAction' => ['required', Rule::in(array_keys(CustodyService::COMPLETION_ACTIONS))],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ], ['completionAction.required' => 'Choose what was done.', 'reason.required' => 'Add a short remark.', 'reason.min' => 'Add a short remark on what was done.']);

        $this->run(function () use ($custody) {
            $custody->complete($this->document(), $this->userOrFail(), $this->completionAction, $this->reason);

            return 'The document is completed. You are its final holder.';
        });
    }

    public function reportFound(FoundDocumentReports $reports): void
    {
        $this->validate(['note' => ['nullable', 'string', 'max:500']]);

        abort_if($this->isInvolved($this->document(), $this->userOrFail()), 403);

        $this->run(function () use ($reports) {
            $reports->report($this->document(), $this->userOrFail(), $this->note);

            return 'Thank you. The people responsible have been told the document is with you. Please keep it safe until it is collected, or hand it to your nearest registry.';
        });
    }

    private function run(callable $action): void
    {
        try {
            $this->notice = $action();
            $this->errorMessage = null;
            $this->closeForm();
            $this->dispatch('handover-changed');
        } catch (CustodyException $exception) {
            $this->notice = null;
            $this->errorMessage = $exception->getMessage();
        }
    }

    private function document(): PdtsDocument
    {
        return PdtsDocument::findOrFail($this->documentId);
    }

    private function pendingOrFail(): DocumentMovement
    {
        $movement = $this->document()->pendingMovement()->with('document')->first();

        if (! $movement) {
            throw new CustodyException('This handover is no longer waiting. The page has been refreshed.');
        }

        return $movement;
    }

    private function isInvolved(PdtsDocument $document, User $user): bool
    {
        return app(CustodyService::class)->canViewHistory($document, $user);
    }

    public function render(CustodyService $custody, FoundDocumentReports $reports, DocumentTracker $tracker)
    {
        $user = Auth::user();
        $document = PdtsDocument::with([
            'documentType',
            'currentCustodian',
            'registrar',
            'primaryAttachment',
            'latestReceipt',
            'pendingMovement.sender',
            'pendingMovement.recipient',
            'pendingMovement.messenger',
        ])->findOrFail($this->documentId);

        $pending = $document->pendingMovement;
        $status = $document->statusEnum();

        // Not logged in: view-only tracking (reference, where it is going, who
        // has it, the journey). Every action is done in the app after logging in.
        if (! $user) {
            return view('livewire.pdts.scan-page', [
                'document' => $document,
                'guest' => true,
                'involved' => true,
                'pending' => $pending,
                'status' => $status,
                'isHolder' => false,
                'can' => [
                    // View-only: reference, where it is going and who has it.
                    'pickup' => false,
                    'deliver' => false,
                    'acknowledge' => false,
                    'decline' => false, 'cancel' => false, 'forward' => false, 'sendBack' => false, 'complete' => false,
                ],
                'waitingForPickup' => false,
                'waitingForDelivery' => false,
                'heldSince' => $document->latestReceipt?->acknowledged_at ?? $document->registered_at,
                'events' => $tracker->for($document)['events'],
                'alreadyReported' => false,
                'returnCategories' => [],
                'completionActions' => [],
                'fullPageUrl' => null,
            ])->layout('layouts.scan', ['title' => $document->reference_number]);
        }

        $involved = $this->isInvolved($document, $user);
        $isHolder = (int) $document->current_custodian_id === (int) $user->id;

        $can = [];
        if ($involved) {
            $can = [
                'pickup' => $pending && (int) $pending->messenger_id === (int) $user->id && ! $pending->picked_up_at,
                'deliver' => $pending && (int) $pending->messenger_id === (int) $user->id && $pending->picked_up_at && ! $pending->delivered_at,
                'acknowledge' => $pending && $custody->isRecipientOrDelegate($pending, $user) && $user->can('documents.acknowledge'),
                'decline' => $pending && (int) $pending->recipient_id === (int) $user->id,
                'cancel' => $pending && $custody->canCancel($pending, $user),
                'forward' => ! $pending && $custody->forwardBlockedReason($document, $user) === null,
                'sendBack' => ! $pending && $isHolder && $status === DocumentStatus::Received && $custody->returnTarget($document),
                'complete' => ! $pending && $isHolder && $status === DocumentStatus::Received,
            ];
        }

        $view = view('livewire.pdts.scan-page', [
            'document' => $document,
            'guest' => false,
            'involved' => $involved,
            'pending' => $pending,
            'status' => $status,
            'isHolder' => $isHolder,
            'can' => $can,
            // With a messenger, receipt is confirmed once they have picked it up and delivered it.
            'waitingForPickup' => $pending && $pending->messenger_id && ! $pending->picked_up_at,
            'waitingForDelivery' => $pending && $pending->messenger_id && $pending->picked_up_at && ! $pending->delivered_at,
            'heldSince' => $document->latestReceipt?->acknowledged_at ?? $document->registered_at,
            // The journey is shown here, so people on a phone stay on this page.
            'events' => $involved ? $tracker->for($document)['events'] : [],
            'alreadyReported' => ! $involved && $reports->alreadyReported($document, $user),
            'returnCategories' => DocumentMovement::RETURN_CATEGORIES,
            'completionActions' => CustodyService::COMPLETION_ACTIONS,
            // Only people involved get a way into the full app from here.
            'fullPageUrl' => ! $involved ? null : ($user->can('documents.view')
                ? route('pdts.documents.show', $document)
                : route('movements.history', $document)),
        ]);

        return $view->layout('layouts.scan', ['title' => $involved ? $document->reference_number : 'PDTS']);
    }
}
