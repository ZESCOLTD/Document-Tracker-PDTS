<?php

namespace App\Livewire\Movements;

use App\Enums\DocumentStatus;
use App\Livewire\Movements\Concerns\SearchesDocuments;
use App\Models\PdtsDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The initiator's view: every document the signed-in user registered, how far
 * it has got and where it is now. No actions here apart from Track.
 */
class Track extends Component
{
    use SearchesDocuments;
    use WithPagination;

    public const TABS = [
        'all' => 'All',
        'in-progress' => 'In progress',
    ];

    protected $paginationTheme = 'bootstrap';

    #[Url(except: 'all')]
    public string $tab = 'all';

    public function setTab(string $tab): void
    {
        $this->tab = array_key_exists($tab, self::TABS) ? $tab : 'all';
        $this->resetPage();
    }

    public function render()
    {
        $overdueAfterDays = config('pdts.overdue_after_days');
        $cutoff = now()->subDays($overdueAfterDays);

        $counts = [];
        foreach (array_keys(self::TABS) as $key) {
            $counts[$key] = $this->inTab($this->mine(), $key)->count();
        }

        $documents = $this->inTab($this->applyDocumentSearch($this->mine()), $this->tab)
            ->with(['documentType', 'currentCustodian', 'pendingMovement.recipient', 'latestReceipt.recipient'])
            // Stuck documents first, then the newest.
            ->orderByRaw(
                'case when exists (select 1 from pdts_document_movements m where m.open_document_id = pdts_documents.id and m.forwarded_at <= ?) then 0 else 1 end',
                [$cutoff],
            )
            ->latest('id')
            ->paginate(10);

        return view('livewire.movements.track', [
            'documents' => $documents,
            'tabs' => self::TABS,
            'counts' => $counts,
            'cutoff' => $cutoff,
            'overdueAfterDays' => $overdueAfterDays,
        ])->layout('layouts.master', ['title' => 'Track My Documents']);
    }

    /**
     * Documents the signed-in user registered.
     */
    private function mine(): Builder
    {
        return PdtsDocument::query()->where('registered_by', Auth::id());
    }

    private function inTab(Builder $query, string $tab): Builder
    {
        return match ($tab) {
            'in-progress' => $query->whereIn('status', [
                DocumentStatus::Registered->value,
                DocumentStatus::AwaitingReceipt->value,
                DocumentStatus::Received->value,
            ]),
            default => $query,
        };
    }
}
