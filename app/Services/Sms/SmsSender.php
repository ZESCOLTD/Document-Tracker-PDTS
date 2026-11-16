<?php

namespace App\Services\Sms;

use App\Models\PdtsSmsLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends an SMS and records it in pdts_sms_logs.
 *
 * Drivers (config services.sms.driver):
 *  - log:  writes the message to the log file; nothing leaves the server.
 *  - http: posts {to, message, sender} to SMS_API_URL with SMS_API_KEY as a
 *          bearer token. Adjust sendHttp() to the gateway ICT provides.
 */
class SmsSender
{
    /**
     * @param  array{purpose: string, document_id?: ?int, user_id?: ?int, ip?: ?string}  $context
     */
    public function send(string $to, string $message, array $context): bool
    {
        $driver = (string) config('services.sms.driver', 'log');
        $status = 'sent';
        $error = null;

        try {
            match ($driver) {
                'log' => Log::info('[PDTS SMS] to '.$to.': '.$message),
                'http' => $this->sendHttp($to, $message),
                default => throw new \RuntimeException("Unknown SMS driver [{$driver}]."),
            };
        } catch (Throwable $exception) {
            report($exception);
            $status = 'failed';
            $error = mb_substr($exception->getMessage(), 0, 250);
        }

        PdtsSmsLog::create([
            'to' => $to,
            'message' => $message,
            'purpose' => $context['purpose'],
            'pdts_document_id' => $context['document_id'] ?? null,
            'user_id' => $context['user_id'] ?? null,
            'driver' => $driver,
            'status' => $status,
            'error' => $error,
            'ip' => $context['ip'] ?? null,
        ]);

        return $status === 'sent';
    }

    private function sendHttp(string $to, string $message): void
    {
        $url = config('services.sms.url');

        if (! $url) {
            throw new \RuntimeException('SMS_API_URL is not set.');
        }

        Http::timeout((int) config('services.sms.timeout', 10))
            ->withToken((string) config('services.sms.key'))
            ->acceptJson()
            ->post($url, [
                'to' => $to,
                'message' => $message,
                'sender' => config('services.sms.sender'),
            ])
            ->throw();
    }
}
