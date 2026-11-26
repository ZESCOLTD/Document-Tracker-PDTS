<?php

namespace App\Services\Pdts\Closing;

use App\Enums\DocumentStatus;
use App\Models\PdtsDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cancelling a registered document that was issued in error. Only someone
 * with the "cancel documents" permission can, with a reason, and only while
 * no paper is on its way. Its history and files are kept; it simply can no
 * longer be sent, decided on or completed.
 */
class DocumentClosure
{
    public function cancelBlocker(PdtsDocument $document, User $user): ?string
    {
        return match (true) {
            ! $user->can('documents.cancel') => 'Only an administrator can cancel a document.',
            $document->isDraft() => 'A draft is discarded, not cancelled.',
            in_array($document->status, [DocumentStatus::Completed->value, DocumentStatus::Cancelled->value], true) => 'A '.$document->status.' document cannot be cancelled.',
            $document->pendingMovement()->exists() => 'The paper is on its way to someone. Cancel or finish that handover first.',
            default => null,
        };
    }

    public function cancel(PdtsDocument $document, User $user, string $reason): PdtsDocument
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new RuntimeException('Give the reason for cancelling.');
        }
        if ($blocker = $this->cancelBlocker($document, $user)) {
            throw new RuntimeException($blocker);
        }

        return DB::transaction(function () use ($document, $user, $reason) {
            $locked = PdtsDocument::query()->lockForUpdate()->findOrFail($document->getKey());
            if ($blocker = $this->cancelBlocker($locked, $user)) {
                throw new RuntimeException($blocker);
            }

            $locked->update([
                'status' => DocumentStatus::Cancelled->value,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancel_reason' => mb_substr($reason, 0, 1000),
            ]);
            $locked->auditLogs()->create([
                'actor_id' => $user->id,
                'action' => 'document_cancelled',
                'details' => ['reference_number' => $locked->reference_number, 'reason' => $reason],
                'occurred_at' => now(),
            ]);

            return $locked;
        });
    }
}
