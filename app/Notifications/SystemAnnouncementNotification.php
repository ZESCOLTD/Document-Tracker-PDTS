<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Generic broadcast / announcement notification for PDTS.
 *
 * Because it extends the standard LAMS notification pipeline, it is:
 *   - Written to email_log automatically (Phase 8 listener).
 *   - Subject to user notification preferences (Phase 3 trait).
 *   - Rendered with the configured application mail template.
 */
class SystemAnnouncementNotification extends Notification implements ShouldQueue
{
    use Queueable, \App\Notifications\Concerns\RespectsUserPreferences;

    public function __construct(
        protected string $title,
        protected string $bodyMarkdown,
        protected ?string $ctaLabel = null,
        protected ?string $ctaUrl = null,
        protected string $category = 'system',
    ) {}

    public function via($notifiable): array
    {
        return $this->preferredChannels($notifiable, ['mail', 'database'], $this->category);
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title)
            ->greeting('Hello ' . ($notifiable->name ?? 'User') . ',');

        foreach (preg_split("/\r?\n/", $this->bodyMarkdown) as $line) {
            $mail->line($line);
        }

        if ($this->ctaLabel && $this->ctaUrl) {
            $mail->action($this->ctaLabel, $this->ctaUrl);
        }

        return $mail
            ->line('You can control your notification preferences at any time under Settings → Notification Preferences.');
    }

    public function toArray($notifiable): array
    {
        return [
            'title'   => $this->title,
            'message' => \Illuminate\Support\Str::limit(strip_tags($this->bodyMarkdown), 160),
            'url'     => $this->ctaUrl ?? url('/notifications'),
            'icon'    => 'fas fa-bullhorn text-primary',
        ];
    }
}
