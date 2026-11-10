<?php

namespace App\Livewire\Pdts\Documents;

use App\Models\PdtsDocument;
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
        return view('livewire.pdts.documents.show')
            ->layout('layouts.master', ['title' => $this->document->reference_number]);
    }
}