<?php

namespace App\Livewire\Support;

use Livewire\Component;
use Livewire\WithFileUploads;
use App\Models\SupportTicket;
use App\Models\SupportMessage;
use App\Models\SupportAttachment;
use Illuminate\Support\Facades\Auth;
use App\Notifications\Support\NewSupportMessageNotification;
use App\Models\User;

class SupportChat extends Component
{
    use WithFileUploads;

    public $isOpen = false;

    public $message = '';
    public $files = [];

    public $ticket;

    public $pageKey;

    protected $rules = [
        'message' => 'nullable|string|max:5000',
        'files.*' => 'file|max:10240', // 10MB each
    ];

    public function mount()
    {
        // Capture current page (this ties into your ribbon/page system)
        $this->pageKey = request()->route()->getName();

        // Create or load active ticket for this user + page
        $this->ticket = SupportTicket::firstOrCreate([
            'user_id' => Auth::id(),
            'page_key' => $this->pageKey,
            'status' => 'open',
        ], [
            'subject' => 'Support request from ' . $this->pageKey,
        ]);
    }

    public function toggleChat()
    {
        $this->isOpen = !$this->isOpen;
    }

    public function sendMessage()
    {
        $this->validate();

        if (trim($this->message) === '' && empty($this->files)) {
            return;
        }

        // Create message
        $msg = SupportMessage::create([
            'support_ticket_id' => $this->ticket->id,
            'user_id' => Auth::id(),
            'message' => $this->message,
        ]);

        // Attach files if any
        if (!empty($this->files)) {
            foreach ($this->files as $file) {

                $path = $file->store('support-attachments');

                SupportAttachment::create([
                    'support_message_id' => $msg->id,
                    'file_path' => $path,
                    'file_name' => $file->getClientOriginalName(),
                ]);
            }

            $this->files = [];
        }

        // Notify admin(s)
        $admins = User::where('is_admin', 1)->get();

        foreach ($admins as $admin) {
            $admin->notify(new NewSupportMessageNotification($msg));
        }

        $this->message = '';
    }

    public function getMessagesProperty()
    {
        return SupportMessage::with('attachments')
            ->where('support_ticket_id', $this->ticket->id)
            ->latest()
            ->take(50)
            ->get()
            ->reverse();
    }

    public function render()
    {
        return view('livewire.support.support-chat');
    }
}
