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
        'completed' => 'Completed',
    ];

    protected $paginationTheme = 'bootstrap';

    #[Url(except: 'all')]
    public string $tab = 'all';

    public function updatedTab(): void
    {
        $this->setTab($this->tab);
    }

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
            $counts[$key] = $this->documentsQuery($key)->count();
        }

        $documents = $this->documentsQuery()
            ->with(['documentType', 'currentCustodian', 'pendingMovement.recipient', 'pendingMovement.messenger', 'latestReceipt.recipient'])
            // Stuck documents first, then the newest.
            ->orderByRaw(
                'case when exists (select 1 from pdts_document_movements m where m.open_document_id = pdts_documents.id and m.forwarded_at <= ?) then 0 else 1 end',
                [$cutoff],
            )
            ->latest('id')
            ->paginate(10);

        return view('livewire.movements.track', [
            'documents' => $documents,
            'filterCards' => [
                'all' => ['label' => 'All documents', 'icon' => 'fa-layer-group', 'count' => $counts['all']],
                'in-progress' => ['label' => 'In progress', 'icon' => 'fa-hourglass-half', 'tone' => 'amber', 'count' => $counts['in-progress']],
                'completed' => ['label' => 'Completed', 'icon' => 'fa-check-circle', 'tone' => 'green', 'count' => $counts['completed']],
            ],
            'cutoff' => $cutoff,
            'overdueAfterDays' => $overdueAfterDays,
        ])->layout('layouts.master', ['title' => 'Track My Documents']);
    }

    protected function documentsQuery(?string $tab = null): Builder
    {
        return $this->inTab($this->applyDocumentSearch($this->mine()), $tab ?? $this->tab);
    }

    /**
     * Documents the signed-in user registered.
     */
    private function mine(): Builder
    {
        // What the user registered, and what was prepared for them to sign.
        return PdtsDocument::query()->where(fn (Builder $q) => $q->where('registered_by', Auth::id())->orWhere('signatory_id', Auth::id()));
    }

    private function inTab(Builder $query, string $tab): Builder
    {
        return match ($tab) {
            'completed' => $query->where('status', DocumentStatus::Completed->value),
            'in-progress' => $query->whereIn('status', [
                DocumentStatus::Registered->value,
                DocumentStatus::AwaitingReceipt->value,
                DocumentStatus::Received->value,
            ]),
            default => $query,
        };
    }
}
