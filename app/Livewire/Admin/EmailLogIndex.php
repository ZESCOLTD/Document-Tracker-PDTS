<?php

namespace App\Livewire\Admin;

use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class EmailLogIndex extends Component
{
    use WithPagination;

    public string $search = '';
    public string $status = ''; // '', sent, failed
    public string $channel = ''; // '', mail, database

    protected $queryString = [
        'search'  => ['except' => ''],
        'status'  => ['except' => ''],
        'channel' => ['except' => ''],
    ];

    public function updatingSearch(): void  { $this->resetPage(); }
    public function updatingStatus(): void  { $this->resetPage(); }
    public function updatingChannel(): void { $this->resetPage(); }

    public function mount(): void
    {
        if (!$this->userCanAccess()) {
            abort(403, 'You are not allowed to view the email log.');
        }
    }

    protected function userCanAccess(): bool
    {
        $u = Auth::user();
        if (!$u) return false;
        return $u->hasRole('Super Administrator') || $u->can('admin.email_log.view');
    }

    public function resend(int $id): void
    {
        if (!$this->userCanAccess()) return;

        $row = EmailLog::find($id);
        if (!$row || !$row->user_id) {
            session()->flash('error', 'Cannot resend — missing recipient.');
            return;
        }
        $user = User::find($row->user_id);
        if (!$user) {
            session()->flash('error', 'Recipient user no longer exists.');
            return;
        }

        // Resend a plain informational email — we cannot faithfully re-hydrate
        // the original notification without its constructor args, so we surface
        // a courtesy notice instead.
        try {
            \Illuminate\Support\Facades\Mail::raw(
                "This is a manual resend of a prior PDTS notification.\n\n" .
                "Original event: {$row->notification_class}\n" .
                "Subject: " . ($row->subject ?? '—') . "\n" .
                "Original time: {$row->created_at}\n\n" .
                "Please log in to PDTS to see the latest state.",
                function ($m) use ($user, $row) {
                    $m->to($user->email)->subject('[Resend] ' . ($row->subject ?? 'PDTS Notification'));
                }
            );
            session()->flash('success', "Resent notice to {$user->email}.");
        } catch (\Throwable $e) {
            session()->flash('error', 'Resend failed: ' . $e->getMessage());
        }
    }

    public function render()
    {
        $query = EmailLog::query()->latest('id');

        if ($this->search !== '') {
            $s = '%' . $this->search . '%';
            $query->where(function ($q) use ($s) {
                $q->where('email', 'like', $s)
                    ->orWhere('subject', 'like', $s)
                    ->orWhere('notification_class', 'like', $s);
            });
        }
        if ($this->status  !== '') $query->where('status',  $this->status);
        if ($this->channel !== '') $query->where('channel', $this->channel);

        $rows = $query->paginate(25);

        $stats = [
            'total'  => EmailLog::count(),
            'sent'   => EmailLog::where('status', 'sent')->count(),
            'failed' => EmailLog::where('status', 'failed')->count(),
            'today'  => EmailLog::whereDate('created_at', today())->count(),
        ];

        return view('livewire.admin.email-log-index', [
            'rows'  => $rows,
            'stats' => $stats,
        ])->layout('layouts.master', ['title' => 'Notification Audit Log']);
    }
}
