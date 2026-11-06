<?php



namespace App\Livewire\Support;

use App\Models\SupportTicket;
use App\Models\SupportMessage;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithFileUploads;

class ChatBox extends Component
{
    use WithFileUploads;

    public $ticket;
    public $message = '';
    public $file;

    public function mount()
    {
        $this->ticket = SupportTicket::firstOrCreate(
            [
                'user_id' => Auth::id(),
                'status' => 'open'
            ],
            [
                'subject' => 'Support Request'
            ]
        );
    }

    public function sendMessage()
    {
        $this->validate([
            'message' => 'nullable|string',
            'file' => 'nullable|file|max:5120'
        ]);

        $filePath = null;
        $fileName = null;

        if ($this->file) {
            $filePath = $this->file->store('support', 'public');
            $fileName = $this->file->getClientOriginalName();
        }

        SupportMessage::create([
            'support_ticket_id' => $this->ticket->id,
            'user_id' => Auth::id(),
            'message' => $this->message,
            'file_path' => $filePath,
            'file_name' => $fileName,
        ]);

        $this->message = '';
        $this->file = null;

        $this->ticket->refresh();
    }

    public function render()
    {
        return view('livewire.support.chat-box', [
            'messages' => $this->ticket->messages()->latest()->get()
        ]);
    }
}
