<?php

namespace App\Notifications;

use App\Models\PdtsDocument;
use App\Notifications\Concerns\RespectsUserPreferences;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An internal document reached someone in PDTS: it is for their attention,
 * or they were copied.
 */
class DocumentDistributedNotification extends Notification
{
    use RespectsUserPreferences;

    public function __construct(
        public PdtsDocument $document,
        public string $kind, // attention, copy
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
        return match ($this->kind) {
            'copy' => 'Copy for your information',
            default => 'For your attention',
        };
    }

    private function message(): string
    {
        $what = $this->document->reference_number.' ('.$this->document->subject.')';
        $from = $this->document->signatory?->name ?? $this->document->registrar?->name ?? 'A colleague';

        return match ($this->kind) {
            'copy' => "{$from} copied you on {$what}, for information.",
            default => "{$from} sent you {$what}.",
        };
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title(),
            'message' => $this->message(),
            'url' => route('pdts.documents.show', $this->document),
            'icon' => match ($this->kind) {
                'copy' => 'fas fa-copy text-muted',
                default => 'fas fa-envelope-open-text text-primary',
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
