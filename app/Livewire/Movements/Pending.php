<?php

namespace App\Livewire\Movements;

use App\Models\DocumentMovement;
use App\Services\Documents\CustodyException;
use App\Services\Documents\CustodyService;
use App\Services\Documents\WorkCounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Documents forwarded to the signed-in user that are waiting for them to act.
 */
class Pending extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    private const FILTERS = [
        'all' => ['label' => 'Awaiting receipt', 'icon' => 'fa-inbox'],
        'ready' => ['label' => 'Direct handovers', 'icon' => 'fa-share', 'tone' => 'green'],
        'delivered' => ['label' => 'Delivered by messenger', 'icon' => 'fa-truck', 'tone' => 'blue'],
    ];

    #[Url(as: 'status', except: 'all')]
    public string $statusFilter = 'all';

    public function updatedStatusFilter(): void
    {
        if (! array_key_exists($this->statusFilter, self::FILTERS)) {
            $this->statusFilter = 'all';
        }

        $this->resetPage();
        $this->closeDialog();
    }

    #[Locked]
    public ?int $dialogMovementId = null;

    #[Locked]
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

    protected function movementsQuery(?string $filter = null): Builder
    {
        $filter ??= $this->statusFilter;
        $query = app(WorkCounts::class)->pendingAcknowledgementsQuery(Auth::user());

        return match ($filter) {
            'ready' => $query->whereNull('messenger_id'),
            'delivered' => $query->whereNotNull('messenger_id')->whereNotNull('delivered_at'),
            default => $query,
        };
    }

    public function render()
    {
        $query = $this->movementsQuery();

        return view('livewire.movements.pending', [
            'filterCards' => collect(self::FILTERS)->map(fn (array $card, string $filter) => $card + [
                'count' => $this->movementsQuery($filter)->count(),
            ])->all(),
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
