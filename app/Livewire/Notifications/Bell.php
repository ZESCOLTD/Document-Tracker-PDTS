<?php

namespace App\Livewire\Notifications;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Header bell icon showing recent database notifications for the auth user.
 *
 * Data source: Laravel's DatabaseNotification (users_notifications via Notifiable).
 * Poll interval: 60s (via wire:poll in the view).
 */
class Bell extends Component
{
    public int $unreadCount = 0;
    public array $recent = [];

    public function mount(): void
    {
        $this->refresh();
    }

    #[On('notification-read')]
    public function refresh(): void
    {
        $user = Auth::user();
        if (!$user) {
            $this->unreadCount = 0;
            $this->recent = [];
            return;
        }

        // Fail-safe: if the notifications table doesn't exist yet (e.g. pending
        // migration on a fresh environment), silently show an empty bell rather
        // than crashing the entire navbar on every page.
        try {
            $this->unreadCount = (int) $user->unreadNotifications()->count();

            $this->recent = $user->notifications()
                ->latest()
                ->limit(8)
                ->get()
                ->map(fn ($n) => [
                    'id'      => (string) $n->id,
                    'title'   => (string) ($n->data['title']   ?? 'Notification'),
                    'message' => (string) ($n->data['message'] ?? ''),
                    'url'     => (string) ($n->data['url']     ?? '#'),
                    'icon'    => (string) ($n->data['icon']    ?? 'fas fa-bell text-secondary'),
                    'unread'  => is_null($n->read_at),
                    'time'    => optional($n->created_at)->diffForHumans(),
                ])
                ->all();
        } catch (\Throwable $e) {
            $this->unreadCount = 0;
            $this->recent = [];
        }
    }

    public function markAsRead(string $id): void
    {
        $user = Auth::user();
        if (!$user) return;

        try {
            $n = $user->notifications()->whereKey($id)->first();
            if ($n && is_null($n->read_at)) {
                $n->markAsRead();
            }
        } catch (\Throwable $e) {
            // ignore — table may not exist yet
        }

        $this->refresh();
    }

    public function markAllAsRead(): void
    {
        $user = Auth::user();
        if (!$user) return;

        try {
            $user->unreadNotifications()->update(['read_at' => now()]);
        } catch (\Throwable $e) {
            // ignore — table may not exist yet
        }
        $this->refresh();
    }

    public function render()
    {
        return view('livewire.notifications.bell');
    }
}
