<?php

namespace App\Notifications;

use App\Models\PdtsDocument;
use App\Notifications\Concerns\RespectsUserPreferences;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PdtsDocumentApprovalNotification extends Notification
{
    use RespectsUserPreferences;

    public function __construct(
        public PdtsDocument $document,
        public string $event,
        public string $stepName,
        public string $actorName,
        public ?string $comment = null,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = $this->preferredChannels($notifiable, ['database', 'mail'], 'approvals');

        if (! filter_var($notifiable->email ?? '', FILTER_VALIDATE_EMAIL)) {
            $channels = array_values(array_diff($channels, ['mail']));
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->subject())
            ->greeting('Hello '.($notifiable->name ?? 'there').',')
            ->line($this->message());

        if ($this->comment) {
            $mail->line('Comment: '.$this->comment);
        }

        return $mail
            ->action('Open document', route('pdts.documents.show', $this->document))
            ->line('You can manage these messages under Notification Preferences.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->subject(),
            'message' => $this->message(),
            'url' => route('pdts.documents.show', $this->document),
            'icon' => match ($this->event) {
                'approved' => 'fas fa-check-circle text-success',
                'rejected' => 'fas fa-times-circle text-danger',
                default => 'fas fa-stamp text-primary',
            },
        ];
    }

    private function subject(): string
    {
        $subject = match ($this->event) {
            'approved' => 'Document approved',
            'rejected' => 'Document approval rejected',
            'returned', 'returned_to_step' => 'Document returned for changes',
            default => 'Document awaiting your approval',
        };

        return "PDTS | {$subject}: {$this->document->reference_number}";
    }

    private function message(): string
    {
        $reference = $this->document->reference_number;
        $title = $this->document->title;

        return match ($this->event) {
            'submitted' => "{$this->actorName} submitted {$reference} ({$title}) for approval at {$this->stepName}.",
            'step_assigned' => "{$this->actorName} advanced {$reference} ({$title}) to {$this->stepName}; your review is now required.",
            'approved' => "{$this->actorName} approved {$reference} ({$title}) at the final workflow step.",
            'rejected' => "{$this->actorName} rejected approval for {$reference} ({$title}) at {$this->stepName}.",
            'returned_to_step' => "{$this->actorName} returned {$reference} ({$title}) to {$this->stepName} for further review.",
            default => "{$this->actorName} returned {$reference} ({$title}) for changes.",
        };
    }
}