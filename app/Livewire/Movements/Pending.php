<?php

namespace App\Livewire\Movements;

use App\Enums\MovementStatus;
use App\Models\DocumentMovement;
use App\Models\PdtsUserDelegation;
use App\Services\Documents\CustodyException;
use App\Services\Documents\CustodyService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Documents forwarded to the signed-in user that are waiting for them to act.
 */
class Pending extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public ?int $dialogMovementId = null;

    public ?string $dialogMode = null;

    public string $reason = '';

    public ?string $notice = null;

    public ?string $errorMessage = null;

    public function openAcknowledge(int $movementId): void
    {
        $this->openDialog($movementId, 'acknowledge');
    }

    public function openDecline(int $movementId): void
    {
        $this->openDialog($movementId, 'decline');
    }

    public function closeDialog(): void
    {
        $this->reset(['dialogMovementId', 'dialogMode', 'reason']);
        $this->resetValidation();
    }

    public function acknowledge(CustodyService $custody): void
    {
        $this->act(function (DocumentMovement $movement) use ($custody) {
            $custody->acknowledge($movement, Auth::user());

            return "You are now the custodian of {$movement->document->reference_number}.";
        });
    }

    public function decline(CustodyService $custody): void
    {
        $this->validate(['reason' => ['required', 'string', 'max:1000']]);

        $this->act(function (DocumentMovement $movement) use ($custody) {
            $custody->decline($movement, Auth::user(), $this->reason);

            return "You declined {$movement->document->reference_number}. The sender has been told.";
        });
    }

    public function render()
    {
        $delegatedPrincipalIds = PdtsUserDelegation::query()
            ->activeAt()
            ->where('delegate_user_id', Auth::id())
            ->select('principal_user_id');

        $query = DocumentMovement::query()
            ->where(fn ($query) => $query
                ->where('recipient_id', Auth::id())
                ->orWhereIn('recipient_id', $delegatedPrincipalIds))
            ->where('status', MovementStatus::AwaitingReceipt->value)
            ->whereHas('document');

        return view('livewire.movements.pending', [
            'movements' => (clone $query)
                ->with(['sender', 'document' => fn ($q) => $q->with('documentType')->withCount('attachments')])
                ->oldest('forwarded_at')
                ->paginate(10),
            'dialogMovement' => $this->dialogMovementId
                ? (clone $query)->with('sender', 'document')->find($this->dialogMovementId)
                : null,
        ])->layout('layouts.master', ['title' => 'Pending Acknowledgements']);
    }

    private function openDialog(int $movementId, string $mode): void
    {
        $this->resetValidation();
        $this->reset(['reason', 'notice', 'errorMessage']);
        $this->dialogMovementId = $movementId;
        $this->dialogMode = $mode;
    }

    private function act(callable $action): void
    {
        $movement = DocumentMovement::with('document')->find($this->dialogMovementId);

        try {
            if (! $movement) {
                throw new CustodyException('This handover is no longer available.');
            }

            $this->notice = $action($movement);
            $this->errorMessage = null;
        } catch (CustodyException $exception) {
            $this->notice = null;
            $this->errorMessage = $exception->getMessage();
        }

        $this->closeDialog();
    }
}
