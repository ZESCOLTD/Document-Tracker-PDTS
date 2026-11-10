<?php

namespace App\Livewire\Movements;

use App\Models\PdtsDocument;
use App\Services\Documents\CustodyService;
use App\Services\Documents\DocumentTracker;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Tracking view for one document. Works as its own page, and can be embedded:
 * <livewire:movements.history :document="$document" />
 */
class History extends Component
{
    #[Locked]
    public int $documentId;

    public function mount(PdtsDocument $document, CustodyService $custody): void
    {
        abort_unless($custody->canViewHistory($document, Auth::user()), 403);

        $this->documentId = $document->getKey();
    }

    public function render(DocumentTracker $tracker)
    {
        $document = PdtsDocument::findOrFail($this->documentId);

        return view('livewire.movements.history', [
            'document' => $document,
            'track' => $tracker->for($document),
        ])->layout('layouts.master', ['title' => 'Track Document']);
    }
}
