<?php

namespace App\Livewire\Admin\Support;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class SupportCenter extends Component
{
    public $tickets;
    public $selectedTicket;
    public $message;

    public function mount()
    {
        $this->tickets = SupportTicket::latest()->get();
    }

    public function selectTicket($id)
    {
        $this->selectedTicket = SupportTicket::find($id);
    }

    public function sendReply()
    {
        SupportMessage::create([
            'support_ticket_id' => $this->selectedTicket->id,
            'user_id' => auth()->id(),
            'message' => $this->message,
        ]);

        $this->message = '';
        $this->selectedTicket->refresh();
    }

    public function render()
    {
        return view('livewire.admin.support.manage-tickets');
    }
}
