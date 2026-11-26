<?php

namespace App\Notifications;

use App\Models\PdtsDocument;
use App\Models\User;
use App\Notifications\Concerns\RespectsUserPreferences;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A document prepared on someone's behalf moved on: it is waiting for their
 * signature, it was returned for correction, or it was signed.
 */
class SignatureRequestNotification extends Notification
{
    use RespectsUserPreferences;

    public function __construct(
        public PdtsDocument $document,
        public string $event, // awaiting, returned, signed
        public User $actor,
        public ?string $comment = null,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = $this->preferredChannels($notifiable, ['database', 'mail'], 'documents');

        if (filter_var($notifiable->email ?? null, FILTER_VALIDATE_EMAIL) === false) {
            $channels = array_values(array_diff($channels, ['mail']));
        }

        return $channels;
    }

    private function title(): string
    {
        return match ($this->event) {
            'awaiting' => 'Waiting for your signature',
            'returned' => 'Returned for correction',
            'issued' => 'Issued',
            default => 'Signed',
        };
    }

    private function message(): string
    {
        $ref = $this->document->reference_number;
        $subject = $this->document->subject;

        return match ($this->event) {
            'awaiting' => "{$this->actor->name} prepared {$ref} ({$subject}) for you to review and sign.",
            'returned' => "{$this->actor->name} returned {$ref} ({$subject}) for correction: \"{$this->comment}\"",
            'issued' => "{$this->actor->name} issued {$ref} ({$subject}). It is registered and with you to send on.",
            default => "{$this->actor->name} signed {$ref} ({$subject}). It is registered and with you to send on.",
        };
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title(),
            'message' => $this->message(),
            'url' => route('pdts.documents.show', $this->document),
            'icon' => match ($this->event) {
                'awaiting' => 'fas fa-signature text-primary',
                'returned' => 'fas fa-undo text-warning',
                default => 'fas fa-check-circle text-success',
            },
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("PDTS | {$this->title()}: {$this->document->reference_number}")
            ->greeting('Hello '.($notifiable->name ?? ''))
            ->line($this->message())
            ->action('Open the document', route('pdts.documents.show', $this->document));
    }
}
