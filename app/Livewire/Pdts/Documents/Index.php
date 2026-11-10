<?php

namespace App\Livewire\Pdts\Documents;

use App\Models\PdtsDocument;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search = '';

    protected string $paginationTheme = 'bootstrap';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        return view('livewire.pdts.documents.index', [
            'documents' => PdtsDocument::query()
                ->with(['documentType', 'currentCustodian'])
                ->search($this->search)
                ->latest('registered_at')
                ->paginate(15),
        ])->layout('layouts.master', ['title' => 'Registered Documents']);
    }
}