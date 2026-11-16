<?php

namespace App\Notifications;

use App\Models\PdtsDocument;
use App\Models\User;
use App\Notifications\Concerns\RespectsUserPreferences;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Someone not involved scanned a document's QR code and reported that it is
 * with them by mistake (found, or delivered to the wrong person).
 */
class DocumentReportedNotification extends Notification
{
    use RespectsUserPreferences;

    public function __construct(
        public PdtsDocument $document,
        public User $reporter,
        public ?string $note,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = $this->preferredChannels($notifiable, ['database', 'mail'], 'handovers');

        if (filter_var($notifiable->email ?? null, FILTER_VALIDATE_EMAIL) === false) {
            $channels = array_values(array_diff($channels, ['mail']));
        }

        return $channels;
    }

    private function message(): string
    {
        $office = $this->reporter->officeName();
        $who = $this->reporter->name.($office ? " ({$office})" : '');
        $message = "{$who} scanned {$this->document->reference_number} and reported that it reached them by mistake.";

        return $this->note ? $message.' Note: "'.$this->note.'"' : $message;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Document reported found',
            'message' => Str::limit($this->message(), 160),
            'url' => route('movements.history', $this->document),
            'icon' => 'fas fa-flag text-danger',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("PDTS | Document reported found: {$this->document->reference_number}")
            ->greeting('Hello '.($notifiable->name ?? ''))
            ->line($this->message())
            ->line('Please arrange to collect the paper, or contact the person who reported it.')
            ->action('View the document', route('movements.history', $this->document));
    }
}
