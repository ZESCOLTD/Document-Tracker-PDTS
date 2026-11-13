<?php

namespace App\Notifications;

use App\Models\DocumentMovement;
use App\Notifications\Concerns\RespectsUserPreferences;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Notice about a custody handover. Every event is stored in the system. Forwarding
 * (sent to the recipient) and acknowledgement (sent to the sender) are also emailed.
 */
class DocumentHandoverNotification extends Notification
{
    use RespectsUserPreferences;

    public const FORWARDED = 'forwarded';

    public const ACKNOWLEDGED = 'acknowledged';

    public const DECLINED = 'declined';

    public const CANCELLED = 'cancelled';

    public const RETURNED = 'returned';

    public const ASSIGNED = 'assigned';

    public const PICKED_UP = 'picked_up';

    public function __construct(
        public DocumentMovement $movement,
        public string $event,
    ) {}

    public function via(object $notifiable): array
    {
        $defaults = in_array($this->event, [self::FORWARDED, self::ACKNOWLEDGED, self::RETURNED, self::ASSIGNED, self::PICKED_UP], true)
            ? ['database', 'mail']
            : ['database'];

        $channels = $this->preferredChannels($notifiable, $defaults, 'handovers');

        if (filter_var($notifiable->email ?? null, FILTER_VALIDATE_EMAIL) === false) {
            $channels = array_values(array_diff($channels, ['mail']));
        }

        return $channels;
    }

    /**
     * @return array{title: string, message: string, url: string, icon: string}
     */
    private function content(): array
    {
        $document = $this->movement->document;
        $reference = $document->reference_number;
        $sender = $this->movement->sender?->name ?? 'A user';
        $recipient = $this->movement->recipient?->name ?? 'The recipient';
        $acknowledger = $this->movement->acknowledgedBy?->name ?? $recipient;
        $newCustodian = $this->movement->newCustodian?->name ?? $recipient;
        $acknowledgementMessage = (int) $this->movement->acknowledged_by === (int) $this->movement->recipient_id
            ? "{$recipient} acknowledged {$reference} and is now the custodian."
            : "{$acknowledger} acknowledged {$reference} on behalf of {$recipient}; {$newCustodian} is now the recorded custodian.";
        $history = route('movements.history', $document);

        [$title, $message, $url, $icon] = match ($this->event) {
            self::ASSIGNED => [
                'Document pickup assigned',
                "Collect {$reference} from {$sender} and deliver it to {$recipient}.",
                route('movements.deliveries'),
                'fas fa-truck text-warning',
            ],
            self::PICKED_UP => [
                'Document pickup confirmed',
                ($this->movement->messenger?->name ?? 'The messenger')." collected {$reference} for delivery to {$recipient}.",
                $history,
                'fas fa-truck text-info',
            ],
            self::FORWARDED => [
                'Document awaiting your receipt',
                "{$sender} forwarded {$reference}: {$document->title}. Acknowledge it once you physically have the paper.",
                route('movements.pending'),
                'fas fa-share text-warning',
            ],
            self::ACKNOWLEDGED => [
                'Document receipt acknowledged',
                $acknowledgementMessage,
                $history,
                'fas fa-check-circle text-success',
            ],
            self::DECLINED => [
                'Document declined',
                "{$recipient} declined {$reference}: {$this->movement->close_reason}",
                $history,
                'fas fa-times-circle text-danger',
            ],
            self::CANCELLED => [
                'Document handover cancelled',
                "The handover of {$reference} was cancelled: {$this->movement->close_reason}",
                $history,
                'fas fa-ban text-secondary',
            ],
            self::RETURNED => [
                'Document sent back for correction',
                "{$sender} sent back {$reference} for correction (".($this->movement->returnCategoryLabel() ?? 'Other').'): '.$this->movement->remarks,
                route('movements.pending'),
                'fas fa-undo text-warning',
            ],
        };

        return [
            'title' => $title,
            'message' => $message,
            'url' => $url,
            'icon' => $icon,
        ];
    }

    public function toArray(object $notifiable): array
    {
        $content = $this->content();

        return [
            'title' => $content['title'],
            'message' => Str::limit($content['message'], 160),
            'url' => $content['url'],
            'icon' => $content['icon'],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $content = $this->content();
        $reference = $this->movement->document->reference_number;

        return (new MailMessage)
            ->subject("PDTS | {$content['title']}: {$reference}")
            ->greeting('Hello '.($notifiable->name ?? 'there').',')
            ->line($content['message'])
            ->action(in_array($this->event, [self::FORWARDED, self::RETURNED], true) ? 'Review handover' : 'View movement history', $content['url'])
            ->line('You can change how you receive notifications under Settings → Notification Preferences.');
    }
}
