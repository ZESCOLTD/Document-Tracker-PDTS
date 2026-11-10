<?php

namespace App\Services\Documents;

use App\Enums\DocumentStatus;
use App\Enums\MovementStatus;
use App\Models\DocumentMovement;
use App\Models\PdtsDocument;
use App\Models\PdtsUserDelegation;
use App\Models\User;
use App\Notifications\DocumentHandoverNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The only place custody and handover state changes.
 *
 * Every action locks the document row in a transaction, so simultaneous actions
 * cannot produce two pending handovers or two custodians (NFR-04).
 */
class CustodyService
{
    private const MAX_TEXT_LENGTH = 1000;

    public function forward(PdtsDocument $document, User $sender, User $recipient, ?string $remarks = null): DocumentMovement
    {
        $remarks = $this->cleanOptionalText($remarks, 'Remarks');

        try {
            $movement = DB::transaction(function () use ($document, $sender, $recipient, $remarks) {
                $locked = $this->lockDocument($document->getKey());

                if ($reason = $this->forwardBlockedReason($locked, $sender)) {
                    throw new CustodyException($reason);
                }

                if (! $this->eligibleRecipients($sender)->whereKey($recipient->getKey())->exists()) {
                    throw new CustodyException('This person cannot receive documents. Choose another active user.');
                }

                $movement = $locked->movements()->create([
                    'sender_id' => $sender->getKey(),
                    'recipient_id' => $recipient->getKey(),
                    'sender_office' => $sender->officeName(),
                    'recipient_office' => $recipient->officeName(),
                    'status' => MovementStatus::AwaitingReceipt,
                    'forwarded_at' => now(),
                    'remarks' => $remarks,
                    'open_document_id' => $locked->getKey(),
                ]);

                // The sender stays the custodian until the recipient acknowledges (BR-03, BR-04).
                $locked->update(['status' => DocumentStatus::AwaitingReceipt->value]);

                $this->audit($locked, $sender, 'handover_forwarded', $movement, ['remarks' => $remarks]);

                return $movement;
            });
        } catch (UniqueConstraintViolationException) {
            throw new CustodyException('This document already has a handover awaiting receipt.');
        }

        $this->notify(
            [$recipient, ...$this->activeDelegates($recipient)->all()],
            $movement,
            DocumentHandoverNotification::FORWARDED,
        );

        return $movement;
    }

    public function acknowledge(DocumentMovement $movement, User $user): DocumentMovement
    {
        $movement = DB::transaction(function () use ($movement, $user) {
            [$document, $locked] = $this->lockPending($movement);

            if (! $user->can('documents.acknowledge')) {
                throw new CustodyException('You do not have permission to acknowledge documents.');
            }

            $isDelegate = ! $this->isRecipient($locked, $user)
                && $this->isActiveDelegate($locked, $user);

            if (! $this->isRecipient($locked, $user) && ! $isDelegate) {
                throw new CustodyException('Only the intended recipient or an authorized delegate can acknowledge this document.');
            }

            $locked->update([
                'status' => MovementStatus::Acknowledged,
                'acknowledged_at' => now(),
                'acknowledged_by' => $user->getKey(),
                'acknowledged_office' => $user->officeName(),
                'new_custodian_id' => $user->getKey(),
                'open_document_id' => null,
            ]);

            $document->update([
                'current_custodian_id' => $user->getKey(),
                'status' => DocumentStatus::Received->value,
            ]);

            $this->audit($document, $user, 'handover_acknowledged', $locked, [
                'actual_custodian_id' => $user->getKey(),
                'acknowledged_on_behalf_of' => $isDelegate ? $locked->recipient_id : null,
            ]);

            return $locked;
        });

        $this->notify([$movement->sender], $movement, DocumentHandoverNotification::ACKNOWLEDGED);

        return $movement;
    }

    public function decline(DocumentMovement $movement, User $user, string $reason): DocumentMovement
    {
        $reason = $this->cleanReason($reason);

        $movement = DB::transaction(function () use ($movement, $user, $reason) {
            [$document, $locked] = $this->lockPending($movement);

            // No permission check: a recipient who has lost acknowledge rights must
            // still be able to send a document back rather than leave it stuck.
            if (! $this->isRecipient($locked, $user)) {
                throw new CustodyException('Only the intended recipient can decline this document.');
            }

            $this->close($document, $locked, $user, MovementStatus::Declined, $reason);
            $this->audit($document, $user, 'handover_declined', $locked, ['reason' => $reason]);

            return $locked;
        });

        $this->notify([$movement->sender], $movement, DocumentHandoverNotification::DECLINED);

        return $movement;
    }

    public function cancel(DocumentMovement $movement, User $user, string $reason): DocumentMovement
    {
        $reason = $this->cleanReason($reason);

        $movement = DB::transaction(function () use ($movement, $user, $reason) {
            [$document, $locked] = $this->lockPending($movement);

            if (! $this->canCancel($locked, $user)) {
                throw new CustodyException('Only the sender or an authorised user can cancel this handover.');
            }

            $this->close($document, $locked, $user, MovementStatus::Cancelled, $reason);
            $this->audit($document, $user, 'handover_cancelled', $locked, ['reason' => $reason]);

            return $locked;
        });

        $recipients = [$movement->recipient];
        if ((int) $movement->sender_id !== (int) $user->getKey()) {
            $recipients[] = $movement->sender;
        }

        $this->notify($recipients, $movement, DocumentHandoverNotification::CANCELLED);

        return $movement;
    }

    public function complete(PdtsDocument $document, User $user, string $comments): PdtsDocument
    {
        $comments = $this->cleanReason($comments);

        return DB::transaction(function () use ($document, $user, $comments): PdtsDocument {
            $locked = $this->lockDocument($document->getKey());

            if (! $user->can('documents.complete')) {
                throw new CustodyException('You do not have permission to complete documents.');
            }

            if ((int) $locked->current_custodian_id !== (int) $user->getKey()) {
                throw new CustodyException('Only the current custodian can complete this document.');
            }

            if ($locked->pendingMovement()->exists()) {
                throw new CustodyException('Cancel or resolve the pending handover before completing this document.');
            }

            if ($locked->statusEnum() !== DocumentStatus::Received) {
                throw new CustodyException('A document must be acknowledged before it can be completed.');
            }

            $locked->update([
                'status' => DocumentStatus::Completed->value,
                'completed_by' => $user->getKey(),
                'completed_at' => now(),
                'completion_comments' => $comments,
            ]);

            $locked->auditLogs()->create([
                'actor_id' => $user->getKey(),
                'occurred_at' => now(),
                'action' => 'completed',
                'details' => [
                    'completed_by' => $user->getKey(),
                    'completion_comments' => $comments,
                ],
            ]);

            return $locked->fresh(['documentType', 'currentCustodian', 'completedBy']);
        }, attempts: 3);
    }

    /**
     * Why this user cannot forward this document right now, or null if they can.
     */
    public function forwardBlockedReason(PdtsDocument $document, User $user): ?string
    {
        if (! $user->can('documents.forward')) {
            return 'You do not have permission to forward documents.';
        }

        if ((int) $document->current_custodian_id !== (int) $user->getKey()) {
            return 'Only the current custodian can forward this document.';
        }

        if ($document->pendingMovement()->exists()) {
            return 'This document already has a handover awaiting receipt.';
        }

        if (! in_array($document->statusEnum(), [DocumentStatus::Registered, DocumentStatus::Received], true)) {
            return 'A '.strtolower($document->statusEnum()->label()).' document cannot be forwarded.';
        }

        return null;
    }

    public function canCancel(DocumentMovement $movement, User $user): bool
    {
        return (int) $movement->sender_id === (int) $user->getKey()
            || $user->can('documents.cancel');
    }

    /**
     * People involved in the document, plus anyone who can view audit records.
     * The same rule covers access to the scanned copy, so a recipient can check
     * the paper against the scan before acknowledging.
     */
    public function canViewHistory(PdtsDocument $document, User $user): bool
    {
        $userId = $user->getKey();

        return (int) $document->current_custodian_id === (int) $userId
            || $user->can('audit.view')
            || $document->movements()
                ->where(fn (Builder $query) => $query
                    ->where('sender_id', $userId)
                    ->orWhere('recipient_id', $userId))
                ->exists()
            || PdtsUserDelegation::query()
                ->activeAt()
                ->where('delegate_user_id', $userId)
                ->whereIn('principal_user_id', $document->movements()->select('recipient_id'))
                ->exists();
    }

    /**
     * Active users who can acknowledge a document, excluding the sender.
     *
     * @return Builder<User>
     */
    public function eligibleRecipients(User $sender): Builder
    {
        return User::permission('documents.acknowledge')
            ->whereKeyNot($sender->getKey())
            ->where('is_banned', false)
            ->where(fn (Builder $query) => $query
                ->whereNull('banned_until')
                ->orWhere('banned_until', '<=', now()));
    }

    private function lockDocument(int|string $documentId): PdtsDocument
    {
        $document = PdtsDocument::whereKey($documentId)->lockForUpdate()->first();

        if (! $document) {
            throw new CustodyException('This document is no longer available.');
        }

        return $document;
    }

    /**
     * Always locks the document before the movement, so actions cannot deadlock.
     *
     * @return array{0: PdtsDocument, 1: DocumentMovement}
     */
    private function lockPending(DocumentMovement $movement): array
    {
        $document = $this->lockDocument($movement->pdts_document_id);
        $locked = DocumentMovement::whereKey($movement->getKey())->lockForUpdate()->firstOrFail();

        if (! $locked->isPending()) {
            throw new CustodyException('This handover is no longer awaiting receipt.');
        }

        return [$document, $locked];
    }

    private function isRecipient(DocumentMovement $movement, User $user): bool
    {
        return (int) $movement->recipient_id === (int) $user->getKey();
    }

    private function isActiveDelegate(DocumentMovement $movement, User $user): bool
    {
        return PdtsUserDelegation::query()
            ->activeAt()
            ->where('principal_user_id', $movement->recipient_id)
            ->where('delegate_user_id', $user->getKey())
            ->exists();
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function activeDelegates(User $principal): \Illuminate\Support\Collection
    {
        return User::permission('documents.acknowledge')
            ->whereIn('id', PdtsUserDelegation::query()
                ->activeAt()
                ->where('principal_user_id', $principal->getKey())
                ->select('delegate_user_id'))
            ->where('is_banned', false)
            ->where(fn (Builder $query) => $query
                ->whereNull('banned_until')
                ->orWhere('banned_until', '<=', now()))
            ->get();
    }

    /**
     * Ends a pending handover without changing custody.
     */
    private function close(PdtsDocument $document, DocumentMovement $movement, User $user, MovementStatus $status, string $reason): void
    {
        $movement->update([
            'status' => $status,
            'closed_at' => now(),
            'closed_by' => $user->getKey(),
            'close_reason' => $reason,
            'open_document_id' => null,
        ]);

        $hasBeenReceived = $document->movements()
            ->where('status', MovementStatus::Acknowledged->value)
            ->exists();

        $document->update([
            'status' => $hasBeenReceived ? DocumentStatus::Received->value : DocumentStatus::Registered->value,
        ]);
    }

    private function audit(PdtsDocument $document, User $user, string $action, DocumentMovement $movement, array $details = []): void
    {
        $document->auditLogs()->create([
            'actor_id' => $user->getKey(),
            'occurred_at' => now(),
            'action' => $action,
            'details' => [
                'movement_id' => $movement->getKey(),
                'sender_id' => $movement->sender_id,
                'recipient_id' => $movement->recipient_id,
                ...array_filter($details, fn ($value) => $value !== null),
            ],
        ]);
    }

    /**
     * Runs after the transaction commits. A failed notification is reported but
     * must never undo a custody change that has already happened.
     *
     * @param  array<int, User|null>  $users
     */
    private function notify(array $users, DocumentMovement $movement, string $event): void
    {
        try {
            Notification::send(
                array_filter($users),
                new DocumentHandoverNotification($movement, $event),
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function cleanReason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new CustodyException('Please give a reason.');
        }

        return $this->limitLength($reason, 'The reason');
    }

    private function cleanOptionalText(?string $text, string $label): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $this->limitLength($text, $label);
    }

    private function limitLength(string $text, string $label): string
    {
        if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            throw new CustodyException($label.' must be '.self::MAX_TEXT_LENGTH.' characters or fewer.');
        }

        return $text;
    }
}
