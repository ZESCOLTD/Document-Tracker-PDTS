<?php

namespace App\Services\Ai;

use App\Models\DocumentMovement;
use App\Models\PdtsDocument;
use App\Models\User;
use App\Services\Documents\CustodyService;

class DocumentTrackingContext
{
    public function __construct(private readonly CustodyService $custody) {}

    public function forDocument(PdtsDocument $document, User $user): array
    {
        if (! $this->custody->canViewHistory($document, $user)) {
            return ['available' => false];
        }
        $document->load([
            'currentCustodian',
            'pendingMovement.sender', 'pendingMovement.recipient', 'pendingMovement.messenger',
            'latestMovement.sender', 'latestMovement.recipient', 'latestMovement.messenger',
        ]);

        return [
            'available' => true,
            'as_of' => now()->toIso8601String(),
            'reference' => $document->reference_number,
            'status' => $document->status,
            'status_label' => $document->statusEnum()->plainLabel(),
            'current_custodian' => $document->currentCustodian?->name,
            'registered_at' => $document->registered_at?->toIso8601String(),
            'has_been_forwarded' => $document->latestMovement !== null,
            'pending_handover' => $this->handover($document->pendingMovement),
            'latest_handover' => $this->handover($document->latestMovement),
        ];
    }

    private function handover(?DocumentMovement $movement): ?array
    {
        if (! $movement) {
            return null;
        }

        return [
            'status' => $movement->status->value,
            'sender' => $movement->sender?->name,
            'recipient' => $movement->recipient?->name,
            'forwarded_at' => $movement->forwarded_at?->toIso8601String(),
            'messenger' => $movement->messenger?->name,
            'picked_up_at' => $movement->picked_up_at?->toIso8601String(),
            'delivered_at' => $movement->delivered_at?->toIso8601String(),
            'acknowledged_at' => $movement->acknowledged_at?->toIso8601String(),
            'closed_at' => $movement->closed_at?->toIso8601String(),
            'is_return' => $movement->isReturn(),
        ];
    }
}
