<?php

namespace App\Notifications;

use App\Models\DocumentMovement;
use App\Notifications\Concerns\RespectsUserPreferences;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * In-system notice about a handover. Email is planned for Phase 7.
 */
class DocumentHandoverNotification extends Notification
{
    use RespectsUserPreferences;

    public const FORWARDED = 'forwarded';

    public const ACKNOWLEDGED = 'acknowledged';

    public const DECLINED = 'declined';

    public const CANCELLED = 'cancelled';

    public function __construct(
        public DocumentMovement $movement,
        public string $event,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->preferredChannels($notifiable, ['database'], 'handovers');
    }

    public function toArray(object $notifiable): array
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
                route('movements.custody'),
                'fas fa-times-circle text-danger',
            ],
            self::CANCELLED => [
                'Document handover cancelled',
                "The handover of {$reference} was cancelled: {$this->movement->close_reason}",
                $history,
                'fas fa-ban text-secondary',
            ],
        };

        return [
            'title' => $title,
            'message' => Str::limit($message, 160),
            'url' => $url,
            'icon' => $icon,
        ];
    }
}
