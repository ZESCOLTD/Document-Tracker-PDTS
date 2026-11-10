<?php

namespace App\Livewire\Movements\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

/**
 * Search by reference, title or subject, applied when the user presses Search.
 * Used with the livewire.movements.partials.search view.
 */
trait SearchesDocuments
{
    /** What the user is typing. Applied only when they press Search. */
    public string $searchInput = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mountSearchesDocuments(): void
    {
        $this->searchInput = $this->search;
    }

    public function applySearch(): void
    {
        $this->search = trim($this->searchInput);
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->reset(['search', 'searchInput']);
        $this->resetPage();
    }

    /**
     * Limits a documents query to the current search, if there is one.
     */
    protected function applyDocumentSearch(Builder $query): Builder
    {
        $term = mb_strtolower(trim($this->search));

        return $query->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
            ->where(DB::raw('lower(reference_number)'), 'like', "%{$term}%")
            ->orWhere(DB::raw('lower(title)'), 'like', "%{$term}%")
            ->orWhere(DB::raw('lower(subject)'), 'like', "%{$term}%")));
    }
}
