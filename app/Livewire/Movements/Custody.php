<?php

namespace App\Livewire\Movements;

use App\Enums\DocumentStatus;
use App\Enums\MovementStatus;
use App\Livewire\Movements\Concerns\SearchesDocuments;
use App\Models\DocumentMovement;
use App\Models\PdtsDocument;
use App\Models\User;
use App\Services\Documents\CustodyException;
use App\Services\Documents\CustodyService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Open documents the signed-in user currently holds: forward the ones they
 * have, or cancel a handover that has not been acknowledged yet.
 */
class Custody extends Component
{
    use SearchesDocuments;
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public ?int $cancellingMovementId = null;

    public string $reason = '';

    public ?int $returningDocumentId = null;

    public string $returnCategory = '';

    public string $returnReason = '';

    public ?int $returnMessengerId = null;

    public ?int $completingDocumentId = null;

    public string $completionAction = '';

    public string $completionReason = '';

    public ?string $notice = null;

    public ?string $errorMessage = null;

    #[On('handover-changed')]
    public function refreshList(): void
    {
        // Re-renders with the document's new status.
    }

    public function openCancel(int $movementId): void
    {
        $this->resetValidation();
        $this->reset(['reason', 'notice', 'errorMessage']);
        $this->cancellingMovementId = $movementId;
    }

    public function closeCancel(): void
    {
        $this->reset(['cancellingMovementId', 'reason']);
        $this->resetValidation();
    }

    public function cancel(CustodyService $custody): void
    {
        $this->validate(['reason' => ['required', 'string', 'max:1000']]);

        $movement = DocumentMovement::with('document')->find($this->cancellingMovementId);

        try {
            if (! $movement) {
                throw new CustodyException('This handover is no longer available.');
            }

            $custody->cancel($movement, Auth::user(), $this->reason);

            $this->notice = "The handover of {$movement->document->reference_number} was cancelled. You can forward it again.";
            $this->errorMessage = null;
        } catch (CustodyException $exception) {
            $this->notice = null;
            $this->errorMessage = $exception->getMessage();
        }

        $this->closeCancel();
    }

    public function openSendBack(int $documentId): void
    {
        $this->resetValidation();
        $this->reset(['returnCategory', 'returnReason', 'returnMessengerId', 'notice', 'errorMessage']);
        $this->returningDocumentId = $documentId;
    }

    public function closeSendBack(): void
    {
        $this->reset(['returningDocumentId', 'returnCategory', 'returnReason', 'returnMessengerId']);
        $this->resetValidation();
    }

    public function sendBack(CustodyService $custody): void
    {
        $this->validate([
            'returnCategory' => ['required', Rule::in(array_keys(DocumentMovement::RETURN_CATEGORIES))],
            'returnReason' => ['required', 'string', 'max:1000'],
            'returnMessengerId' => ['nullable', 'integer'],
        ]);

        $document = PdtsDocument::find($this->returningDocumentId);

        try {
            if (! $document) {
                throw new CustodyException('This document is no longer available.');
            }

            $messenger = $this->returnMessengerId ? User::find($this->returnMessengerId) : null;
            if ($this->returnMessengerId && ! $messenger) {
                throw new CustodyException('This messenger is no longer available.');
            }

            $movement = $custody->sendBack($document, Auth::user(), $this->returnCategory, $this->returnReason, $messenger);

            $this->notice = "{$document->reference_number} was sent back to {$movement->recipient->name} for correction. You stay the custodian until they acknowledge.";
            $this->errorMessage = null;
        } catch (CustodyException $exception) {
            $this->notice = null;
            $this->errorMessage = $exception->getMessage();
        }

        $this->closeSendBack();
    }

    public function openComplete(int $documentId): void
    {
        $this->resetValidation();
        $this->reset(['completionAction', 'completionReason', 'notice', 'errorMessage']);
        $this->completingDocumentId = $documentId;
    }

    public function closeComplete(): void
    {
        $this->reset(['completingDocumentId', 'completionAction', 'completionReason']);
        $this->resetValidation();
    }

    public function complete(CustodyService $custody): void
    {
        $this->validate([
            'completionAction' => ['required', Rule::in(array_keys(CustodyService::COMPLETION_ACTIONS))],
            'completionReason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $document = PdtsDocument::find($this->completingDocumentId);

            if (! $document) {
                throw new CustodyException('This document is no longer available.');
            }

            $custody->complete($document, Auth::user(), $this->completionAction, $this->completionReason);
            $this->notice = "{$document->reference_number} is completed. You are the final holder.";
            $this->errorMessage = null;
            $this->closeComplete();
            $this->dispatch('handover-changed');
        } catch (CustodyException $exception) {
            $this->errorMessage = $exception->getMessage();
        }
    }

    public function render(CustodyService $custody)
    {
        $documents = $this->applyDocumentSearch(PdtsDocument::query())
            ->with(['documentType', 'pendingMovement.recipient', 'pendingMovement.messenger', 'latestMovement.sender', 'latestMovement.recipient', 'latestMovement.closedBy'])
            ->where('current_custodian_id', Auth::id())
            ->whereIn('status', [
                DocumentStatus::Registered->value,
                DocumentStatus::AwaitingReceipt->value,
                DocumentStatus::Received->value,
            ])
            ->latest('id')
            ->paginate(10);

        // Only the sender's own pending handovers can be opened for cancelling here.
        $cancelling = $this->cancellingMovementId
            ? DocumentMovement::with(['document', 'recipient'])
                ->where('sender_id', Auth::id())
                ->where('status', MovementStatus::AwaitingReceipt->value)
                ->find($this->cancellingMovementId)
            : null;

        // Previous holder for each received document with nothing pending, so the row can show Send back.
        $returnTargets = $documents->getCollection()
            ->filter(fn (PdtsDocument $document) => $document->statusEnum() === DocumentStatus::Received && ! $document->pendingMovement)
            ->mapWithKeys(fn (PdtsDocument $document) => [$document->id => $custody->returnTarget($document)]);

        $returning = $this->returningDocumentId
            ? PdtsDocument::where('current_custodian_id', Auth::id())->find($this->returningDocumentId)
            : null;

        return view('livewire.movements.custody', [
            'documents' => $documents,
            'cancellingMovement' => $cancelling,
            'returnTargets' => $returnTargets,
            'returningDocument' => $returning,
            'returningTarget' => $returning ? $custody->returnTarget($returning) : null,
            'returnMessengers' => $returning
                ? $custody->eligibleMessengers(Auth::user(), $custody->returnTarget($returning))->orderBy('name')->get()
                : collect(),
            'completingDocument' => $this->completingDocumentId
                ? PdtsDocument::where('current_custodian_id', Auth::id())->find($this->completingDocumentId)
                : null,
        ])->layout('layouts.master', ['title' => 'Documents in My Custody']);
    }
}
