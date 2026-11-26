<?php

namespace App\Services\Documents;

use App\Enums\DocumentStatus;
use App\Enums\MovementStatus;
use App\Models\DocumentMovement;
use App\Models\PdtsDocument;
use App\Models\PdtsUserDelegation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * How many items wait on a user, for the sidebar badges. Each count matches
 * the list on its page, so the number and the page never disagree.
 */
class WorkCounts
{
    /** @var array<int, array<string, int>> */
    private array $cache = [];

    /**
     * Handovers waiting for the user to acknowledge, including those sent to
     * people who have delegated their acknowledgements to them.
     */
    public function pendingAcknowledgementsQuery(User $user): Builder
    {
        $delegatedPrincipalIds = PdtsUserDelegation::query()
            ->activeAt()
            ->where('delegate_user_id', $user->id)
            ->select('principal_user_id');

        return DocumentMovement::query()
            ->where(fn (Builder $query) => $query
                ->where('recipient_id', $user->id)
                ->orWhereIn('recipient_id', $delegatedPrincipalIds))
            ->where('status', MovementStatus::AwaitingReceipt->value)
            ->where(fn (Builder $query) => $query->whereNull('messenger_id')->orWhereNotNull('delivered_at'))
            ->whereHas('document');
    }

    public function custodyQuery(User $user): Builder
    {
        return PdtsDocument::query()->where(fn (Builder $query) => $query
            ->where('current_custodian_id', $user->id)
            ->orWhereHas('pendingMovement', fn (Builder $movement) => $movement
                ->where('messenger_id', $user->id)->whereNotNull('picked_up_at')));
    }

    /**
     * @return array{pending: int, custody: int, deliveries: int}
     */
    public function for(User $user): array
    {
        return $this->cache[$user->id] ??= [
            'pending' => $this->pendingAcknowledgementsQuery($user)->count(),
            'custody' => $this->custodyQuery($user)
                ->whereIn('status', [
                    DocumentStatus::Registered->value,
                    DocumentStatus::AwaitingReceipt->value,
                    DocumentStatus::Received->value,
                ])
                ->count(),
            'deliveries' => DocumentMovement::query()
                ->where('messenger_id', $user->id)
                ->where('status', MovementStatus::AwaitingReceipt->value)
                ->whereNull('picked_up_at')
                ->whereHas('document')
                ->count(),
        ];
    }
}
