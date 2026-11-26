<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;

/** Tells ICT verifiers, in PDTS, that a signature is waiting to be verified. */
class SignaturePendingNotification extends Notification
{
    public function __construct(public User $owner) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Signature to verify',
            'message' => $this->owner->name.($this->owner->man_no ? ' ('.$this->owner->man_no.')' : '').' uploaded a signature for verification.',
            'url' => route('pdts.signatures.verification'),
            'icon' => 'fas fa-user-shield text-primary',
        ];
    }
}
