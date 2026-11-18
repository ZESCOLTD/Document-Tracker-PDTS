<?php

namespace App\Services\Sms;

use App\Enums\DocumentStatus;
use App\Models\PdtsDocument;
use App\Services\Documents\DocumentTracker;
use Illuminate\Support\Str;

/**
 * A document's whereabouts and journey as a short SMS (at most three parts).
 */
class DocumentHistorySms
{
    public const MAX_LENGTH = 450;

    private const MAX_EVENTS = 5;

    public function __construct(private readonly DocumentTracker $tracker) {}

    public function for(PdtsDocument $document): string
    {
        $document->loadMissing(['currentCustodian', 'pendingMovement.recipient', 'latestReceipt']);

        $lines = ['PDTS '.$document->reference_number.': '.Str::limit((string) $document->title, 40, '...')];
        $lines[] = 'Now: '.$this->whereNow($document);

        $events = array_reverse(array_slice($this->tracker->for($document)['events'], 0, self::MAX_EVENTS));
        if ($events) {
            $lines[] = 'History:';
            foreach ($events as $event) {
                $who = $event['person'] ? ' - '.$this->shortName($event['person']) : '';
                $lines[] = ($event['at']?->format('d M H:i') ?? '').' '.$event['title'].$who;
            }
        }

        return Str::limit(implode("\n", $lines), self::MAX_LENGTH, '...');
    }

    private function whereNow(PdtsDocument $document): string
    {
        $pending = $document->pendingMovement;

        if ($pending) {
            return 'on its way to '.$this->shortName($pending->recipient?->name ?? 'a former user')
                .', sent '.$pending->forwarded_at?->format('d M H:i');
        }

        $holder = $this->shortName($document->currentCustodian?->name ?? 'a former user');

        if ($document->statusEnum() === DocumentStatus::Completed) {
            return 'completed, final holder '.$holder;
        }

        $since = $document->latestReceipt?->acknowledged_at ?? $document->registered_at;

        return 'with '.$holder.($since ? ' since '.$since->format('d M H:i') : '');
    }

    /** "CHIMUKA MOONDE" becomes "C. Moonde", to keep the SMS short. */
    private function shortName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        if (count($parts) < 2) {
            return Str::title($name);
        }

        return Str::upper(mb_substr($parts[0], 0, 1)).'. '.Str::title(end($parts));
    }
}
