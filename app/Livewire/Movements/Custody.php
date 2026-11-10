<?php

namespace App\Livewire\Movements;

use App\Enums\DocumentStatus;
use App\Enums\MovementStatus;
use App\Livewire\Movements\Concerns\SearchesDocuments;
use App\Models\DocumentMovement;
use App\Models\PdtsDocument;
use App\Services\Documents\CustodyException;
use App\Services\Documents\CustodyService;
use Illuminate\Support\Facades\Auth;
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

    public function render()
    {
        $documents = $this->applyDocumentSearch(PdtsDocument::query())
            ->with(['documentType', 'pendingMovement.recipient', 'latestMovement.sender', 'latestMovement.recipient', 'latestMovement.closedBy'])
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

        return view('livewire.movements.custody', [
            'documents' => $documents,
            'cancellingMovement' => $cancelling,
        ])->layout('layouts.master', ['title' => 'Documents in My Custody']);
    }
}
