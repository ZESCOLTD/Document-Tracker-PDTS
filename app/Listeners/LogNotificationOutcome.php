<?php

namespace App\Listeners;

use App\Models\EmailLog;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Writes one row to email_log for every notification-channel dispatch.
 *
 * Registered in AppServiceProvider::boot() using Event::listen().
 */
class LogNotificationOutcome
{
    public function sent(NotificationSent $event): void
    {
        $this->write($event->notifiable, $event->notification, $event->channel, 'sent', null);
    }

    public function failed(NotificationFailed $event): void
    {
        $error = null;
        $data  = $event->data ?? [];
        if (is_array($data) && isset($data['exception']) && $data['exception'] instanceof \Throwable) {
            $error = $data['exception']->getMessage();
        }
        $this->write($event->notifiable, $event->notification, $event->channel, 'failed', $error);
    }

    protected function write(mixed $notifiable, object $notification, string $channel, string $status, ?string $error): void
    {
        try {
            $email = null;
            $userId = null;
            if (is_object($notifiable) && method_exists($notifiable, 'getKey')) {
                $userId = (int) $notifiable->getKey();
                $email  = (string) ($notifiable->email ?? '');
            }

            $subject = null;
            $payload = null;
            if ($channel === 'mail' && method_exists($notification, 'toMail')) {
                try {
                    $mail = $notification->toMail($notifiable);
                    $subject = (string) ($mail->subject ?? '');
                } catch (\Throwable) { /* best effort */ }
            }
            if ($channel === 'database' && method_exists($notification, 'toArray')) {
                try {
                    $payload = $notification->toArray($notifiable);
                    if (!$subject && is_array($payload)) {
                        $subject = (string) ($payload['title'] ?? '');
                    }
                } catch (\Throwable) { /* best effort */ }
            }

            EmailLog::create([
                'user_id'            => $userId,
                'email'              => $email,
                'notification_class' => get_class($notification),
                'channel'            => $channel,
                'status'             => $status,
                'subject'            => $subject,
                'error'              => $error,
                'payload'            => $payload,
            ]);
        } catch (\Throwable) {
            // Never let audit-logging break notification dispatch.
        }
    }
}
