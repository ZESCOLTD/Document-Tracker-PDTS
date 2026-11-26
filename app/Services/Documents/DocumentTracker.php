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
 * Messenger pickup and recipient receipt are separate stops. Declined and
 * cancelled handovers remain in the timeline without creating receipt stops.
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
            'movements.messenger',
            'movements.acknowledgedBy',
            'movements.closedBy',
        ]);

        $movements = $document->movements
            ->sortBy(fn (DocumentMovement $m) => sprintf('%020d-%020d', $m->forwarded_at?->getTimestamp() ?? 0, $m->getKey()))
            ->values();

        $received = $movements->filter(fn (DocumentMovement $m) => $m->status === MovementStatus::Acknowledged)->values();
        $pending = $movements->first(fn (DocumentMovement $m) => $m->isPending());
        $completedAt = $document->auditLogs->firstWhere('action', 'document_completed')?->occurred_at;

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
            'details' => array_filter([$this->heldFor($registeredAt, $received->first()?->acknowledged_at)]),
            'remarks' => null,
        ]];

        foreach ($movements as $movement) {
            $office = $this->recipientOffice($movement);
            $sender = $movement->sender?->name;
            $forwarded = "Forwarded by {$sender} on ".$movement->forwarded_at?->format('d M Y H:i');

            if ($movement->messenger_id && $movement->picked_up_at) {
                $stops[] = $this->stop('Messenger custody', $movement->messenger?->officeName() ?? self::NO_OFFICE, $movement->messenger?->name, $movement->picked_up_at);
            }

            if ($movement->status === MovementStatus::Acknowledged) {
                $receivedOffice = $movement->acknowledged_office ?? $office;
                $receivedPerson = $movement->newCustodian?->name ?? $movement->acknowledgedBy?->name ?? $movement->recipient?->name;
                $stops[] = $this->stop('Received', $receivedOffice, $receivedPerson, $movement->acknowledged_at);
                $next = $received->get($received->search(fn ($m) => $m->is($movement)) + 1);

                $receivedEvent = $this->event('received', 'Received', $receivedOffice, $movement, $movement->acknowledged_at, [
                    $forwarded,
                    'Acknowledged by '.$movement->acknowledgedBy?->name,
                    $this->heldFor($movement->acknowledged_at, $next?->acknowledged_at ?? $completedAt),
                ]);
                $receivedEvent['person'] = $receivedPerson;
                $events[] = $receivedEvent;
            } elseif ($movement->isPending()) {
                $pendingLabel = $movement->messenger_id && ! $movement->delivered_at
                    ? ($movement->picked_up_at ? 'Awaiting delivery' : 'Awaiting pickup')
                    : 'Awaiting Receipt';
                $stops[] = $this->stop($pendingLabel, $office, $movement->recipient?->name, $movement->delivered_at ?? $movement->forwarded_at);

                $events[] = $this->event('pending', $pendingLabel, $office, $movement, $movement->forwarded_at, [
                    "Forwarded by {$sender}, not yet acknowledged",
                ]);
            } else {
                $declined = $movement->status === MovementStatus::Declined;

                $events[] = $this->event($movement->status->value, $movement->status->label(), $office, $movement, $movement->closed_at, [
                    $forwarded,
                    ($declined ? 'Declined by ' : 'Cancelled by ').$movement->closedBy?->name,
                    'Reason: '.$movement->close_reason,
                    $movement->delivered_at ? 'Return assigned to the messenger' : ($movement->picked_up_at ? 'Closed without recipient acknowledgement' : 'The document did not move'),
                ]);
            }
        }

        foreach ($document->auditLogs->whereIn('action', ['handover_picked_up', 'handover_delivered', 'document_completed']) as $audit) {
            $isCompletion = $audit->action === 'document_completed';
            $isDelivery = $audit->action === 'handover_delivered';
            $movement = $movements->firstWhere('id', $audit->details['movement_id'] ?? null);
            $events[] = [
                'type' => $isCompletion ? 'completed' : ($isDelivery ? 'delivered' : 'picked_up'),
                'title' => $isCompletion ? 'Completed' : ($isDelivery ? 'Delivered; awaiting acknowledgement' : 'Picked up'),
                'office' => $isCompletion ? $this->currentOffice($document, $received) : (($isDelivery ? $movement?->recipient_office : $movement?->sender_office) ?? self::NO_OFFICE),
                'person' => $audit->actor?->name,
                'at' => $audit->occurred_at,
                'details' => $isCompletion ? [CustodyService::COMPLETION_ACTIONS[$audit->details['action_type'] ?? ''] ?? 'Other'] : [],
                'remarks' => $audit->details['reason'] ?? null,
            ];
        }

        $stops = $this->markStates($stops, (bool) $pending);
        if ($document->statusEnum() === DocumentStatus::Completed) {
            $stops[array_key_last($stops)]['state'] = 'ended';
            $stops[array_key_last($stops)]['label'] = 'Completed';
        }

        return [
            'stops' => $stops,
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

    private function markStates(array $stops, bool $hasPending): array
    {
        $last = array_key_last($stops);

        if ($hasPending) {
            $stops[$last]['state'] = 'pending';
            $stops[$last - 1]['state'] = 'current';
        } else {
            $stops[$last]['state'] = 'current';
        }

        return $stops;
    }

    private function heldFor(?CarbonInterface $from, ?CarbonInterface $until): ?string
    {
        if (! $from) {
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

        if ($document->statusEnum() === DocumentStatus::Completed) {
            return [
                'headline' => 'Completed',
                'detail' => "{$custodian} is the final holder.",
                'since' => $document->auditLogs->firstWhere('action', 'document_completed')?->occurred_at,
            ];
        }

        if ($pending) {
            if ($pending->messenger_id) {
                return [
                    'headline' => $pending->delivered_at ? 'Delivered; awaiting acknowledgement' : ($pending->picked_up_at ? 'In messenger custody' : 'Awaiting messenger pickup'),
                    'detail' => $pending->delivered_at
                        ? "{$pending->recipient?->name} must acknowledge receipt. {$pending->messenger?->name} remains responsible until then."
                        : ($pending->picked_up_at ? "{$pending->messenger?->name} is carrying the document to {$pending->recipient?->name}." : "{$custodian} holds the document until {$pending->messenger?->name} confirms pickup."),
                    'since' => $pending->delivered_at ?? $pending->picked_up_at ?? $pending->forwarded_at,
                ];
            }

            return [
                'headline' => 'On its way to '.$this->placeName($this->recipientOffice($pending), $pending->recipient?->name),
                'detail' => "{$pending->recipient?->name} has not acknowledged receipt yet. {$custodian} remains responsible for it.",
                'since' => $pending->forwarded_at,
            ];
        }

        return [
            'headline' => 'With '.$this->placeName($this->currentOffice($document, $received), $custodian),
            'detail' => "{$custodian} holds this document.",
            'since' => $received->last()?->acknowledged_at ?? $document->registered_at ?? $document->created_at,
        ];
    }
}
