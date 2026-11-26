<?php

namespace App\Services\Pdts\Distribution;

use App\Models\PdtsDocument;
use App\Models\User;
use App\Notifications\DocumentDistributedNotification;
use Throwable;

/**
 * After an internal document is registered: the person it is for and the
 * people copied are told and can open it.
 *
 * None of this is a handover: the paper is moved, if at all, by forwarding as
 * usual. Custody and movements are never touched here.
 *
 * Decisions (approve, not approve, return, with a signed decision slip) were
 * taken out for now; they are kept on the local branch future/approvals.
 */
class DocumentDistribution
{
    /** Tells the person it is for and the people copied. Called once, when the document is registered. */
    public function onRegistered(PdtsDocument $document): void
    {
        $document->loadMissing('signatory', 'registrar', 'recipientUser');
        $sender = $document->signatoryId();
        $told = [];

        if ($document->recipientUser && (int) $document->recipient_user_id !== $sender) {
            $this->tell($document->recipientUser, $document, 'attention');
            $told[] = (int) $document->recipient_user_id;
        }

        foreach ($document->copiedUsers() as $person) {
            if ((int) $person->id !== $sender && ! in_array((int) $person->id, $told, true)) {
                $this->tell($person, $document, 'copy');
                $told[] = (int) $person->id;
            }
        }

        if ($told) {
            $document->auditLogs()->create([
                'actor_id' => $sender,
                'action' => 'distributed',
                'details' => array_filter([
                    'reference_number' => $document->reference_number,
                    'recipient_id' => $document->recipient_user_id,
                    'copied' => array_values(array_diff($told, [(int) $document->recipient_user_id])) ?: null,
                ]),
                'occurred_at' => now(),
            ]);
        }
    }

    private function tell(User $person, PdtsDocument $document, string $kind): void
    {
        try {
            $person->notify(new DocumentDistributedNotification($document, $kind));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
