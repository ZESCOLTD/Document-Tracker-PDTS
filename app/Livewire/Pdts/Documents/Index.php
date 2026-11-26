<?php

namespace App\Livewire\Pdts\Documents;

use App\Models\PdtsDocument;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search = '';

    protected string $paginationTheme = 'bootstrap';

    private const FILTERS = [
        'all' => ['label' => 'All documents', 'icon' => 'fa-layer-group'],
        'registered' => ['label' => 'Registered', 'icon' => 'fa-file-alt'],
        'awaiting_receipt' => ['label' => 'Awaiting receipt', 'icon' => 'fa-hourglass-half', 'tone' => 'amber'],
        'received' => ['label' => 'Received', 'icon' => 'fa-inbox', 'tone' => 'blue'],
        'completed' => ['label' => 'Completed', 'icon' => 'fa-check-circle', 'tone' => 'green'],
    ];

    #[Url(as: 'status', except: 'all')]
    public string $statusFilter = 'all';

    public function updatedStatusFilter(): void
    {
        if (! array_key_exists($this->statusFilter, self::FILTERS)) {
            $this->statusFilter = 'all';
        }

        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    protected function documentsQuery(?string $filter = null): Builder
    {
        $filter ??= $this->statusFilter;

        return PdtsDocument::query()->search($this->search)
            ->when($filter !== 'all' && array_key_exists($filter, self::FILTERS), fn (Builder $query) => $query->where('status', $filter));
    }

    public function render()
    {
        return view('livewire.pdts.documents.index', [
            'filterCards' => collect(self::FILTERS)->map(fn (array $card, string $filter) => $card + [
                'count' => $this->documentsQuery($filter)->count(),
            ])->all(),
            'documents' => $this->documentsQuery()
                ->with(['documentType', 'currentCustodian'])
                ->latest('registered_at')
                ->paginate(15),
        ])->layout('layouts.master', ['title' => 'Registered Documents']);
    }
}
