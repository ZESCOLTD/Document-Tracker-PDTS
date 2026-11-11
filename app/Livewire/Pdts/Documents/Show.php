<?php

namespace App\Livewire\Pdts\Documents;

use App\Models\PdtsDocument;
use App\Models\User;
use App\Services\Documents\CustodyService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class Show extends Component
{
    public PdtsDocument $document;

    public function mount(PdtsDocument $document): void
    {
        $this->document = $document;
    }

    #[On('handover-changed')]
    public function refreshDocument(): void
    {
        // Re-renders after a forward from the embedded form.
    }

    public function render()
    {
        $this->document->refresh()->load([
            'documentType',
            'registrar',
            'currentCustodian',
            'attachments.uploader',
            'auditLogs.actor',
            'pendingMovement.recipient',
            'latestReceipt',
        ]);

        $user = Auth::user();
        $custody = app(CustodyService::class);

        // Names of the people each handover was sent to, for the activity list.
        $recipientIds = $this->document->auditLogs->pluck('details.recipient_id')->filter()->unique();
        $people = $recipientIds->isEmpty()
            ? collect()
            : User::withTrashed()->whereKey($recipientIds)->pluck('name', 'id');

        return view('livewire.pdts.documents.show', [
            'canViewHistory' => $custody->canViewHistory($this->document, $user),
            'canForward' => $user->can('documents.forward') && $this->document->current_custodian_id === $user->id,
            'heldSince' => $this->document->latestReceipt?->acknowledged_at ?? $this->document->registered_at,
            'people' => $people,
        ])->layout('layouts.master', ['title' => $this->document->reference_number]);
    }
}
