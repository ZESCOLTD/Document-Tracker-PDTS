<?php

namespace App\Livewire\Movements;

use App\Enums\MovementStatus;
use App\Models\DocumentMovement;
use App\Services\Documents\CustodyException;
use App\Services\Documents\CustodyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Deliveries extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    private const FILTERS = [
        'active' => ['label' => 'Active deliveries', 'icon' => 'fa-truck'],
        'awaiting-pickup' => ['label' => 'Awaiting pickup', 'icon' => 'fa-box', 'tone' => 'amber'],
        'in-transit' => ['label' => 'In transit', 'icon' => 'fa-shipping-fast', 'tone' => 'blue'],
        'awaiting-receipt' => ['label' => 'Awaiting acknowledgement', 'icon' => 'fa-inbox', 'tone' => 'blue'],
        'completed' => ['label' => 'Completed deliveries', 'icon' => 'fa-check-circle', 'tone' => 'green'],
    ];

    #[Url(as: 'status', except: 'active')]
    public string $statusFilter = 'active';

    public ?string $pickupNotice = null;

    public ?string $pickupError = null;

    #[Locked]
    public ?int $pickupMovementId = null;

    public function updatedStatusFilter(): void
    {
        if (! array_key_exists($this->statusFilter, self::FILTERS)) {
            $this->statusFilter = 'active';
        }

        $this->resetPage();
        $this->closePickup();
    }

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
            $this->pickupNotice = 'Pickup confirmed. Open My Custody to deliver this document.';
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

    protected function deliveriesQuery(?string $filter = null): Builder
    {
        $filter ??= $this->statusFilter;
        $query = DocumentMovement::query()
            ->where('messenger_id', Auth::id())
            ->whereHas('document');

        if ($filter === 'completed') {
            return $query->where('status', MovementStatus::Acknowledged->value)
                ->latest('acknowledged_at')
                ->latest('id');
        }

        return $query->where('status', MovementStatus::AwaitingReceipt->value)
            ->when($filter === 'awaiting-pickup', fn (Builder $query) => $query->whereNull('picked_up_at'))
            ->when($filter === 'in-transit', fn (Builder $query) => $query->whereNotNull('picked_up_at')->whereNull('delivered_at'))
            ->when($filter === 'awaiting-receipt', fn (Builder $query) => $query->whereNotNull('delivered_at'))
            ->oldest('forwarded_at');
    }

    public function render(CustodyService $custody)
    {
        abort_unless($custody->canViewDeliveries(Auth::user()), 403);

        return view('livewire.movements.deliveries', [
            'filterCards' => collect(self::FILTERS)->map(fn (array $card, string $filter) => $card + [
                'count' => $this->deliveriesQuery($filter)->count(),
            ])->all(),
            'pickupMovement' => $this->pickupMovement(),
            'deliveries' => $this->deliveriesQuery()
                ->with(['document', 'sender', 'recipient'])
                ->paginate(10),
        ])->layout('layouts.master', ['title' => 'My Deliveries']);
    }
}
