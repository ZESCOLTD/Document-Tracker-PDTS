<?php

namespace App\Services\Documents;

use App\Enums\DocumentStatus;
use App\Enums\MovementStatus;
use App\Models\DocumentMovement;
use App\Models\PdtsDocument;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Turns a document's records into what the tracking page shows: the stops the
 * document has reached, and a timeline of everything that happened.
 *
 * Declined and cancelled handovers appear in the timeline but are not stops,
 * because the document never moved. Future stops are not shown, because a
 * document's route is not known in advance.
 */
class DocumentTracker
{
    public const NO_OFFICE = 'Office not recorded';

    public function for(PdtsDocument $document): array
    {
        $document->loadMissing([
            'currentCustodian',
            'registrar',
            'auditLogs.actor',
            'movements.sender',
            'movements.recipient',
            'movements.acknowledgedBy',
            'movements.closedBy',
        ]);

        $movements = $document->movements
            ->sortBy(fn (DocumentMovement $m) => sprintf('%020d-%020d', $m->forwarded_at?->getTimestamp() ?? 0, $m->getKey()))
            ->values();

        $received = $movements->filter(fn (DocumentMovement $m) => $m->status === MovementStatus::Acknowledged)->values();
        $pending = $movements->first(fn (DocumentMovement $m) => $m->isPending());
        $ended = in_array($document->statusEnum(), [DocumentStatus::Completed, DocumentStatus::Cancelled], true);

        $registeredLog = $document->auditLogs->firstWhere('action', 'registered');
        $registrant = $document->registrar ?? $registeredLog?->actor ?? $movements->first()?->sender ?? $document->currentCustodian;
        $registeredAt = $document->registered_at ?? $registeredLog?->occurred_at ?? $document->created_at;
        $registeredOffice = $document->originating_office ?: $movements->first()?->sender_office ?? $registrant?->officeName() ?? self::NO_OFFICE;

        $stops = [$this->stop('Registered', $registeredOffice, $registrant?->name, $registeredAt)];
        $events = [[
            'type' => 'registered',
            'title' => 'Registered',
            'office' => $registeredOffice,
            'person' => $registrant?->name,
            'at' => $registeredAt,
            'details' => array_filter([$this->heldFor($registeredAt, $received->first()?->acknowledged_at, $ended)]),
            'remarks' => null,
        ]];

        foreach ($movements as $movement) {
            $office = $this->recipientOffice($movement);
            $sender = $movement->sender?->name;
            $forwarded = "Forwarded by {$sender} on ".$movement->forwarded_at?->format('d M Y H:i');

            if ($movement->status === MovementStatus::Acknowledged) {
                $receivedOffice = $movement->acknowledged_office ?? $office;
                $receivedPerson = $movement->newCustodian?->name ?? $movement->acknowledgedBy?->name ?? $movement->recipient?->name;
                $stops[] = $this->stop('Received', $receivedOffice, $receivedPerson, $movement->acknowledged_at);
                $next = $received->get($received->search(fn ($m) => $m->is($movement)) + 1);

                $receivedEvent = $this->event('received', 'Received', $receivedOffice, $movement, $movement->acknowledged_at, [
                    $forwarded,
                    'Acknowledged by '.$movement->acknowledgedBy?->name,
                    $this->heldFor($movement->acknowledged_at, $next?->acknowledged_at, $ended),
                ]);
                $receivedEvent['person'] = $receivedPerson;
                $events[] = $receivedEvent;
            } elseif ($movement->isPending()) {
                $stops[] = $this->stop('Awaiting Receipt', $office, $movement->recipient?->name, $movement->forwarded_at);

                $events[] = $this->event('pending', 'Awaiting Receipt', $office, $movement, $movement->forwarded_at, [
                    "Forwarded by {$sender}, not yet acknowledged",
                ]);
            } else {
                $declined = $movement->status === MovementStatus::Declined;

                $events[] = $this->event($movement->status->value, $movement->status->label(), $office, $movement, $movement->closed_at, [
                    $forwarded,
                    ($declined ? 'Declined by ' : 'Cancelled by ').$movement->closedBy?->name,
                    'Reason: '.$movement->close_reason,
                    'The document did not move',
                ]);
            }
        }

        if ($ended) {
            $endedLog = $document->auditLogs->firstWhere('action', $document->status);
            $stops[] = $this->stop($document->statusEnum()->label(), $this->currentOffice($document, $received), $endedLog?->actor?->name, $endedLog?->occurred_at);
        }

        return [
            'stops' => $this->markStates($stops, (bool) $pending, $ended),
            'events' => collect($events)
                ->map(fn (array $event, int $index) => $event + ['order' => $index])
                ->sortByDesc(fn (array $event) => [$event['at']?->getTimestamp() ?? 0, $event['order']])
                ->values()
                ->all(),
            ...$this->summary($document, $received, $pending),
        ];
    }

    private function stop(string $label, string $office, ?string $person, ?CarbonInterface $at): array
    {
        return ['label' => $label, 'office' => $office, 'person' => $person, 'at' => $at, 'state' => 'done'];
    }

    private function event(string $type, string $title, string $office, DocumentMovement $movement, ?CarbonInterface $at, array $details): array
    {
        return [
            'type' => $type,
            'title' => $title,
            'office' => $office,
            'person' => $movement->recipient?->name,
            'at' => $at,
            'details' => array_values(array_filter($details)),
            'remarks' => $movement->remarks,
        ];
    }

    private function markStates(array $stops, bool $hasPending, bool $ended): array
    {
        $last = array_key_last($stops);

        if ($ended) {
            $stops[$last]['state'] = 'ended';
        } elseif ($hasPending) {
            $stops[$last]['state'] = 'pending';
            $stops[$last - 1]['state'] = 'current';
        } else {
            $stops[$last]['state'] = 'current';
        }

        return $stops;
    }

    private function heldFor(?CarbonInterface $from, ?CarbonInterface $until, bool $ended): ?string
    {
        if (! $from || ($until === null && $ended)) {
            return null;
        }

        $duration = $this->duration($from, $until ?? now());

        return $until ? "Held for {$duration}" : "Held for {$duration} so far";
    }

    private function duration(CarbonInterface $from, CarbonInterface $until): string
    {
        return $from->diffForHumans($until, CarbonInterface::DIFF_ABSOLUTE, true, 2);
    }

    private function recipientOffice(DocumentMovement $movement): string
    {
        return $movement->recipient_office ?? $movement->recipient?->officeName() ?? self::NO_OFFICE;
    }

    /**
     * @param  Collection<int, DocumentMovement>  $received
     */
    private function currentOffice(PdtsDocument $document, Collection $received): string
    {
        $latest = $received->last();

        return $latest
            ? ($latest->acknowledged_office ?? $latest->newCustodian?->officeName() ?? $this->recipientOffice($latest))
            : ($document->originating_office ?: $document->movements->first()?->sender_office ?? $document->currentCustodian?->officeName() ?? self::NO_OFFICE);
    }

    private function placeName(string $office, ?string $person): string
    {
        return $office === self::NO_OFFICE ? ($person ?? $office) : $office;
    }

    /**
     * @param  Collection<int, DocumentMovement>  $received
     */
    private function summary(PdtsDocument $document, Collection $received, ?DocumentMovement $pending): array
    {
        $custodian = $document->currentCustodian?->name ?? 'The custodian';

        if ($pending) {
            return [
                'headline' => 'On its way to '.$this->placeName($this->recipientOffice($pending), $pending->recipient?->name),
                'detail' => "{$pending->recipient?->name} has not acknowledged receipt yet. {$custodian} remains responsible for it.",
                'since' => $pending->forwarded_at,
            ];
        }

        if (in_array($document->statusEnum(), [DocumentStatus::Completed, DocumentStatus::Cancelled], true)) {
            return ['headline' => $document->statusEnum()->label(), 'detail' => null, 'since' => null];
        }

        return [
            'headline' => 'With '.$this->placeName($this->currentOffice($document, $received), $custodian),
            'detail' => "{$custodian} holds this document.",
            'since' => $received->last()?->acknowledged_at ?? $document->registered_at ?? $document->created_at,
        ];
    }
}
