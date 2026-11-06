<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Mail\Message;

/**
 * Sends a plain test email through the currently configured MAIL_* transport.
 *
 * Usage:
 *   php artisan pdts:mail-test someone@zesco.co.zm
 *   php artisan pdts:mail-test someone@zesco.co.zm --subject="PDTS ping"
 */
class MailTestCommand extends Command
{
    protected $signature = 'pdts:mail-test
                            {email : Recipient email address}
                            {--subject=PDTS Mail Test : Subject line}';

    protected $description = 'Send a test email to verify SMTP / mail configuration.';

    public function handle(): int
    {
        $to = (string) $this->argument('email');
        $subject = (string) $this->option('subject');

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error("Invalid email address: {$to}");
            return self::INVALID;
        }

        $this->info("Sending test email to {$to} via " . config('mail.default') . '...');
        $this->line('From: ' . config('mail.from.address') . ' (' . config('mail.from.name') . ')');
        $this->line('Host: ' . config('mail.mailers.smtp.host') . ':' . config('mail.mailers.smtp.port'));

        try {
            Mail::raw(
                "This is a PDTS SMTP connectivity test.\n\n" .
                "If you received this message the mail transport is working correctly.\n" .
                'Timestamp: ' . now()->toDateTimeString(),
                function (Message $message) use ($to, $subject) {
                    $message->to($to)->subject($subject);
                }
            );
        } catch (\Throwable $e) {
            $this->error('FAILED: ' . $e->getMessage());
            $this->line('Trace: ' . $e->getFile() . ':' . $e->getLine());
            return self::FAILURE;
        }

        $this->info('Sent. Check the recipient inbox (and spam folder).');
        return self::SUCCESS;
    }
}
