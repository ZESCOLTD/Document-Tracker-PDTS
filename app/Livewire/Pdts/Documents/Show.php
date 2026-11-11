<?php

namespace App\Livewire\Pdts\Documents;

use App\Models\PdtsDocument;
use App\Services\Documents\CustodyService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Show extends Component
{
    public PdtsDocument $document;

    public function mount(PdtsDocument $document): void
    {
        $this->document = $document->load([
            'documentType',
            'registrar',
            'currentCustodian',
            'attachments.uploader',
            'auditLogs.actor',
        ]);
    }

    public function render()
    {
        $this->document->refresh();
        $canViewHistory = app(CustodyService::class)->canViewHistory($this->document, Auth::user());

        return view('livewire.pdts.documents.show')
            ->with(compact('canViewHistory'))
            ->layout('layouts.master', ['title' => $this->document->reference_number]);
    }
}
