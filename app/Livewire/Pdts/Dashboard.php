<?php

namespace App\Livewire\Pdts;

use Livewire\Component;

class Dashboard extends Component
{
    public function render()
    {
        return view('livewire.pdts.dashboard', [
            'firstName' => str(auth()->user()->name ?? 'there')->before(' ')->toString(),
        ])->layout('layouts.master', [
            'title' => 'PDTS Dashboard',
        ]);
    }
}