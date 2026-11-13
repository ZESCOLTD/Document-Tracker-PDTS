<?php

namespace App\Livewire\Movements;

use App\Enums\MovementStatus;
use App\Models\DocumentMovement;
use App\Services\Documents\CustodyException;
use App\Services\Documents\CustodyService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class Deliveries extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public ?string $pickupNotice = null;

    public ?string $pickupError = null;

    #[Locked]
    public ?int $pickupMovementId = null;

    public function openPickup(int $movementId): void
    {
        $this->reset(['pickupNotice', 'pickupError']);
        $this->pickupMovementId = $movementId;

        if (! $this->pickupMovement()) {
            $this->pickupMovementId = null;
            $this->pickupError = 'This pickup is no longer available or is not assigned to you.';
        }
    }

    public function closePickup(): void
    {
        $this->pickupMovementId = null;
    }

    public function confirmPickup(int $movementId, CustodyService $custody): void
    {
        $this->reset(['pickupNotice', 'pickupError']);

        try {
            if ($this->pickupMovementId !== $movementId) {
                throw new CustodyException('Open the pickup dialog and confirm the selected document.');
            }

            $movement = $this->pickupMovement();
            if (! $movement) {
                throw new CustodyException('This pickup is no longer available or is not assigned to you.');
            }

            $custody->confirmPickup($movement, Auth::user());
            $this->pickupNotice = 'Pickup confirmed.';
        } catch (CustodyException $exception) {
            $this->pickupError = $exception->getMessage();
        }

        $this->closePickup();
    }

    private function pickupMovement(): ?DocumentMovement
    {
        return $this->pickupMovementId
            ? DocumentMovement::with(['document', 'sender', 'recipient'])
                ->where('messenger_id', Auth::id())
                ->where('status', MovementStatus::AwaitingReceipt->value)
                ->whereNull('picked_up_at')
                ->whereHas('document')
                ->find($this->pickupMovementId)
            : null;
    }

    public function render(CustodyService $custody)
    {
        abort_unless($custody->canViewDeliveries(Auth::user()), 403);

        return view('livewire.movements.deliveries', [
            'pickupMovement' => $this->pickupMovement(),
            'deliveries' => DocumentMovement::query()
                ->where('messenger_id', Auth::id())
                ->where('status', MovementStatus::AwaitingReceipt->value)
                ->whereHas('document')
                ->with(['document', 'sender', 'recipient'])
                ->oldest('forwarded_at')
                ->paginate(10),
        ])->layout('layouts.master', ['title' => 'My Deliveries']);
    }
}
