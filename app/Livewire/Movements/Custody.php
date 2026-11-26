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
use App\Services\Documents\WorkCounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
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

    private const FILTERS = [
        'active' => ['label' => 'Active documents', 'icon' => 'fa-folder-open'],
        'to-deliver' => ['label' => 'To deliver', 'icon' => 'fa-truck', 'tone' => 'amber'],
        'delivered' => ['label' => 'Awaiting acknowledgement', 'icon' => 'fa-inbox', 'tone' => 'blue'],
        'awaiting_receipt' => ['label' => 'Awaiting receipt', 'icon' => 'fa-hourglass-half', 'tone' => 'amber'],
        'received' => ['label' => 'Received', 'icon' => 'fa-inbox', 'tone' => 'blue'],
        'completed' => ['label' => 'Completed by me', 'icon' => 'fa-check-circle', 'tone' => 'green'],
    ];

    #[Url(as: 'status', except: 'active')]
    public string $statusFilter = 'active';

    #[Locked]
    public ?int $deliveringMovementId = null;

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

    public function updatedStatusFilter(): void
    {
        if (! array_key_exists($this->statusFilter, self::FILTERS)) {
            $this->statusFilter = 'active';
        }

        $this->resetPage();
        $this->closeCancel();
        $this->closeSendBack();
        $this->closeComplete();
        $this->closeDelivery();
    }

    public function openDelivery(int $movementId): void
    {
        $this->reset(['notice', 'errorMessage']);
        $this->deliveringMovementId = $movementId;
        if (! $this->deliveryMovement()) {
            $this->closeDelivery();
            $this->errorMessage = 'This delivery is not available or is not assigned to you.';
        }
    }

    public function closeDelivery(): void
    {
        $this->deliveringMovementId = null;
    }

    public function deliver(CustodyService $custody): void
    {
        try {
            $movement = $this->deliveryMovement();
            if (! $movement) {
                throw new CustodyException('This delivery is not available or is not assigned to you.');
            }
            $custody->confirmDelivery($movement, Auth::user());
            $this->notice = 'Delivery confirmed. You remain responsible until the recipient acknowledges receipt.';
            $this->errorMessage = null;
            $this->dispatch('handover-changed');
        } catch (CustodyException $exception) {
            $this->errorMessage = $exception->getMessage();
        }
        $this->closeDelivery();
    }

    private function deliveryMovement(): ?DocumentMovement
    {
        return $this->deliveringMovementId
            ? DocumentMovement::with(['document', 'recipient'])
                ->where('messenger_id', Auth::id())
                ->where('status', MovementStatus::AwaitingReceipt->value)
                ->whereNotNull('picked_up_at')->whereNull('delivered_at')
                ->whereHas('document')->find($this->deliveringMovementId)
            : null;
    }

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

            $this->notice = "{$document->reference_number} was sent back to {$movement->recipient->name} for correction. "
                .($messenger ? 'You stay the custodian until messenger pickup.' : 'You stay the custodian until they acknowledge.');
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

    protected function documentsQuery(?string $filter = null): Builder
    {
        $filter ??= $this->statusFilter;
        $query = $this->applyDocumentSearch(app(WorkCounts::class)->custodyQuery(Auth::user()));

        if (in_array($filter, ['to-deliver', 'delivered'], true)) {
            return $query->whereHas('pendingMovement', fn (Builder $movement) => $movement
                ->where('messenger_id', Auth::id())->whereNotNull('picked_up_at')
                ->when($filter === 'to-deliver', fn (Builder $query) => $query->whereNull('delivered_at'), fn (Builder $query) => $query->whereNotNull('delivered_at')));
        }

        if ($filter === 'completed') {
            return $query->where('status', DocumentStatus::Completed->value)
                ->whereHas('auditLogs', fn (Builder $audit) => $audit
                    ->where('action', 'document_completed')
                    ->where('actor_id', Auth::id()));
        }

        if (in_array($filter, ['awaiting_receipt', 'received'], true)) {
            return $query->where('status', $filter);
        }

        return $query->whereIn('status', [
            DocumentStatus::Registered->value,
            DocumentStatus::AwaitingReceipt->value,
            DocumentStatus::Received->value,
        ]);
    }

    public function render(CustodyService $custody)
    {
        abort_unless($custody->canViewCustody(Auth::user()), 403);

        $documents = $this->documentsQuery()
            ->with(['documentType', 'pendingMovement.recipient', 'pendingMovement.messenger', 'latestMovement.sender', 'latestMovement.recipient', 'latestMovement.closedBy'])
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
            'deliveringMovement' => $this->deliveryMovement(),
            'filterCards' => collect(self::FILTERS)
                ->filter(fn (array $card, string $filter) => Auth::user()->can('documents.forward') || in_array($filter, ['active', 'to-deliver', 'delivered'], true))
                ->map(fn (array $card, string $filter) => $card + [
                    'count' => $this->documentsQuery($filter)->count(),
                ])->all(),
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
