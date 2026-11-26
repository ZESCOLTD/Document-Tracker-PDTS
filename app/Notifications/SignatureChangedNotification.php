<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\UserSignatureEvent;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells someone their electronic signature or its PIN/password changed, so a
 * change they did not make is noticed. Security notices are always sent,
 * whatever the person's notification preferences.
 */
class SignatureChangedNotification extends Notification
{
    public function __construct(
        public string $action,
        public ?User $actor = null,
    ) {}

    public function via(object $notifiable): array
    {
        return filter_var($notifiable->email ?? null, FILTER_VALIDATE_EMAIL) === false
            ? ['database']
            : ['database', 'mail'];
    }

    private function message(object $notifiable): string
    {
        $what = UserSignatureEvent::LABELS[$this->action] ?? 'Your electronic signature was changed';
        $by = $this->actor && (int) $this->actor->getKey() !== (int) $notifiable->getKey()
            ? ' by '.$this->actor->name
            : '';

        return $what.$by.' on '.now()->format('j M Y \a\t H:i').'.';
    }

    /** A decision by ICT (approve, reject, suspend, reinstate), not a change the person made. */
    private function byIct(): bool
    {
        return in_array($this->action, ['image_approved', 'image_rejected', 'signature_suspended', 'signature_reinstated'], true);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->byIct() ? 'Your signature: '.strtolower(str_replace(' by ICT', '', UserSignatureEvent::LABELS[$this->action])) : 'Your electronic signature changed',
            'message' => $this->message($notifiable).($this->byIct() ? ' Open My Signature for the details.' : ' If this was not you, contact ICT.'),
            'url' => route('settings.signature'),
            'icon' => 'fas fa-signature text-warning',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('PDTS | Your electronic signature changed')
            ->greeting('Hello '.($notifiable->name ?? ''))
            ->line($this->message($notifiable))
            ->line($this->byIct()
                ? 'Open My Signature in PDTS for the details.'
                : 'If you did not make this change, contact ICT straight away so your signature can be secured.')
            ->action('Open My Signature', route('settings.signature'));
    }
}
