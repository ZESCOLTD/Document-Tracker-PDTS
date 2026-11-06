<?php

namespace App\Livewire\Notifications;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $filter = 'all'; // all|unread|read

    public function updatingFilter(): void
    {
        $this->resetPage();
    }

    public function markAsRead(string $id): void
    {
        $user = Auth::user();
        if (!$user) return;
        $user->notifications()->whereKey($id)->update(['read_at' => now()]);
    }

    public function markAllAsRead(): void
    {
        $user = Auth::user();
        if (!$user) return;
        $user->unreadNotifications()->update(['read_at' => now()]);
    }

    public function render()
    {
        $user = Auth::user();
        $query = $user ? $user->notifications() : \App\Models\User::query()->whereRaw('1=0');

        if ($this->filter === 'unread') {
            $query->whereNull('read_at');
        } elseif ($this->filter === 'read') {
            $query->whereNotNull('read_at');
        }

        $notifications = $query->latest()->paginate(20);

        return view('livewire.notifications.index', [
            'notifications' => $notifications,
        ])->layout('layouts.master', ['title' => 'Notifications']);
    }
}
