<?php

namespace App\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class NotificationPreferences extends Component
{
    public bool $notify_email = true;

    public bool $notify_in_app = true;

    /** category => bool */
    public array $categories = [
        'documents' => true,
        'handovers' => true,
        'system' => true,
    ];

    public array $categoryLabels = [
        'documents' => 'Document registration and updates',
        'handovers' => 'Forwarding and receipt acknowledgement',
        'system' => 'System announcements',
    ];

    public function mount(): void
    {
        $u = Auth::user();
        if (! $u) {
            return;
        }

        $this->notify_email = (bool) ($u->notify_email ?? true);
        $this->notify_in_app = (bool) ($u->notify_in_app ?? true);

        $stored = $u->notify_categories ?? [];
        foreach ($this->categories as $key => $_default) {
            if (array_key_exists($key, $stored)) {
                $this->categories[$key] = (bool) $stored[$key];
            }
        }
    }

    public function save(): void
    {
        $u = Auth::user();
        if (! $u) {
            return;
        }

        $u->forceFill([
            'notify_email' => $this->notify_email,
            'notify_in_app' => $this->notify_in_app,
            'notify_categories' => $this->categories,
        ])->save();

        session()->flash('success', 'Notification preferences updated.');
    }

    public function render()
    {
        return view('livewire.settings.notification-preferences')
            ->layout('layouts.master', ['title' => 'Notification Preferences']);
    }
}
