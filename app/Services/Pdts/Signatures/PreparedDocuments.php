<?php

namespace App\Services\Pdts\Signatures;

use App\Models\PdtsDocument;
use App\Models\PdtsDocumentVersion;
use App\Models\PdtsUserDelegation;
use App\Models\User;
use App\Notifications\SignatureRequestNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Prepare on behalf of: a secretary (the preparer) prepares an internal
 * document for an officer (the signatory), who reviews it and either signs it
 * with their own signature or returns it for correction with a comment.
 *
 * Only an explicit, active "prepare documents" authorisation lets someone
 * prepare for an officer; being a secretary, an assistant or in the same
 * directorate never does. Revoking it takes effect at once.
 *
 * All of this happens while the document is a draft: custody and movements
 * are not touched. Once signed, the preparer holds it and sends it on.
 */
class PreparedDocuments
{
    public const DISCARDED = 'discarded';

    /**
     * Officers this person may prepare documents for, by an active
     * authorisation, most recent first.
     *
     * @return Collection<int, User>
     */
    public function officersFor(User $preparer): Collection
    {
        return User::query()
            ->whereIn('id', $this->authorisations()->where('delegate_user_id', $preparer->getKey())->select('principal_user_id'))
            ->where('is_banned', false)
            ->orderBy('name')
            ->get()
            // Only while they are a Director, Senior Manager or Manager.
            ->filter(fn (User $officer) => $officer->canHavePreparers())
            ->values();
    }

    public function canPrepareFor(User $preparer, int $officerId): bool
    {
        if ((int) $preparer->getKey() === $officerId) {
            return true;
        }

        return $this->authorisations()
            ->where('delegate_user_id', $preparer->getKey())
            ->where('principal_user_id', $officerId)
            ->exists()
            && (bool) User::find($officerId)?->canHavePreparers();
    }

    /** May this person still work on (edit, send, discard) this draft as its preparer? */
    public function isActivePreparer(PdtsDocument $document, User $user): bool
    {
        return (int) $document->registered_by === (int) $user->getKey()
            && $this->canPrepareFor($user, $document->signatoryId());
    }

    public function canEdit(PdtsDocument $document, User $user): bool
    {
        return $this->isOpenDraft($document)
            && $document->signature_status !== PdtsDocument::SIGNATURE_AWAITING
            && $this->isActivePreparer($document, $user);
    }

    public function isOpenDraft(PdtsDocument $document): bool
    {
        return $document->isDraft() && $document->signature_status !== self::DISCARDED;
    }

    /** What a version keeps: everything that goes into the document. */
    public function snapshot(PdtsDocument $document): array
    {
        return [
            'title' => $document->title,
            'subject' => $document->subject,
            'description' => $document->description,
            'document_type_id' => $document->document_type_id,
            'signatory_id' => $document->signatoryId(),
            'recipient_user_id' => $document->recipient_user_id,
            'cc_user_ids' => array_values((array) ($document->cc_user_ids ?? [])),
            'template_data' => $document->template_data ?? [],
        ];
    }

    public function recordVersion(PdtsDocument $document, User $user, string $reason): PdtsDocumentVersion
    {
        $previous = $document->versions()->first();
        $data = $this->snapshot($document);

        return $document->versions()->create([
            'version' => ($previous?->version ?? 0) + 1,
            'created_by' => $user->getKey(),
            'reason' => $reason,
            'data' => $data,
            'changed_fields' => $previous ? $this->changedFields($previous->data, $data) : null,
            'created_at' => now(),
        ]);
    }

    /**
     * Names of what changed between two versions, in words.
     *
     * @return list<string>
     */
    public function changedFields(array $before, array $after): array
    {
        $labels = ['title' => 'Short title', 'subject' => 'Subject', 'description' => 'Note', 'document_type_id' => 'Document type', 'signatory_id' => 'Signatory',
            'recipient_user_id' => 'Sent to', 'cc_user_ids' => 'Copies'];
        $changed = [];

        // A short title left to follow the subject is not a change of its own.
        $titleFollowsSubject = ($before['title'] ?? null) === ($before['subject'] ?? null) && ($after['title'] ?? null) === ($after['subject'] ?? null);

        foreach ($labels as $key => $label) {
            if ($key === 'title' && $titleFollowsSubject) {
                continue;
            }
            if (($before[$key] ?? null) != ($after[$key] ?? null)) {
                $changed[] = $label;
            }
        }

        $headings = ['to' => 'To', 'from' => 'From', 'cc' => 'CC', 'office_reference' => 'Office reference', 'date' => 'Date'];
        foreach ($headings as $key => $label) {
            if (($before['template_data'][$key] ?? null) != ($after['template_data'][$key] ?? null)) {
                $changed[] = $label;
            }
        }

        $oldCustom = (array) ($before['template_data']['custom'] ?? []);
        $newCustom = (array) ($after['template_data']['custom'] ?? []);
        foreach (array_unique([...array_keys($oldCustom), ...array_keys($newCustom)]) as $key) {
            if (trim((string) ($oldCustom[$key] ?? '')) !== trim((string) ($newCustom[$key] ?? ''))) {
                $changed[] = $key === 'text_body' ? 'Body' : ucfirst(str_replace('_', ' ', preg_replace('/^text_/', '', $key)));
            }
        }

        return $changed;
    }

    /** The preparer sends the draft to the signatory to review and sign. */
    public function sendForSignature(PdtsDocument $document, User $preparer): void
    {
        if (! $document->isPreparedOnBehalf()) {
            throw new RuntimeException('You sign this document yourself.');
        }
        if (! $this->isOpenDraft($document) || ! in_array($document->signature_status, [PdtsDocument::SIGNATURE_WITH_PREPARER, PdtsDocument::SIGNATURE_RETURNED], true)) {
            throw new RuntimeException('This draft cannot be sent for signature now.');
        }
        if (! $this->isActivePreparer($document, $preparer)) {
            throw new RuntimeException('You are no longer authorised to prepare documents for '.$document->signatory?->name.'.');
        }

        DB::transaction(function () use ($document, $preparer) {
            $document->update(['signature_status' => PdtsDocument::SIGNATURE_AWAITING]);
            $this->log($document, $preparer, 'sent_for_signature', ['signatory_id' => $document->signatory_id]);
        });

        $this->tell($document->signatory, $document, 'awaiting', $preparer);
    }

    /** The signatory returns the draft with a comment saying what to change. */
    public function returnForCorrection(PdtsDocument $document, User $signatory, string $comment): void
    {
        $comment = trim($comment);
        if (mb_strlen($comment) < 5) {
            throw new RuntimeException('Say what needs to be corrected.');
        }
        if ((int) $document->signatoryId() !== (int) $signatory->getKey() || ! $document->isPreparedOnBehalf()) {
            throw new RuntimeException('Only '.$document->signatory?->name.' can return this document.');
        }
        if (! $this->isOpenDraft($document) || $document->signature_status !== PdtsDocument::SIGNATURE_AWAITING) {
            throw new RuntimeException('This draft is not waiting for your signature.');
        }

        DB::transaction(function () use ($document, $signatory, $comment) {
            $document->update(['signature_status' => PdtsDocument::SIGNATURE_RETURNED, 'signature_note' => $comment]);
            $this->log($document, $signatory, 'returned_for_correction', ['comment' => $comment]);
        });

        $this->tell($document->registrar, $document, 'returned', $signatory, $comment);
    }

    /**
     * Discards a draft that will not be signed. The preparer can while they
     * are authorised; the signatory always can (for example after the
     * secretary's authorisation was revoked). The record is kept.
     */
    public function discard(PdtsDocument $document, User $user, string $reason = ''): void
    {
        if (! $this->isOpenDraft($document)) {
            throw new RuntimeException('Only an unsigned draft can be discarded.');
        }

        $isSignatory = (int) $document->signatoryId() === (int) $user->getKey();
        if (! $isSignatory && ! $this->isActivePreparer($document, $user)) {
            throw new RuntimeException('You cannot discard this draft.');
        }

        DB::transaction(function () use ($document, $user, $reason) {
            $document->update(['signature_status' => self::DISCARDED]);
            $this->log($document, $user, 'draft_discarded', array_filter(['reason' => trim($reason)]));
        });
    }

    public function log(PdtsDocument $document, User $user, string $action, array $details = []): void
    {
        $document->auditLogs()->create([
            'actor_id' => $user->getKey(),
            'action' => $action,
            'details' => ['reference_number' => $document->reference_number, ...$details],
            'occurred_at' => now(),
        ]);
    }

    public function tell(?User $user, PdtsDocument $document, string $event, User $actor, ?string $comment = null): void
    {
        if (! $user || (int) $user->getKey() === (int) $actor->getKey()) {
            return;
        }

        try {
            $user->notify(new SignatureRequestNotification($document, $event, $actor, $comment));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The officer authorises someone to prepare documents for them to sign,
     * from a start date, until an end date or indefinitely. Only preparing:
     * it never lets them receive documents or sign.
     */
    public function grant(User $officer, User $preparer, \DateTimeInterface $startsAt, ?\DateTimeInterface $expiresAt, ?string $reason, User $grantedBy): PdtsUserDelegation
    {
        if (! $officer->canHavePreparers()) {
            throw new RuntimeException('Only Directors, Senior Managers and Managers can have someone prepare documents for them.');
        }
        if ((int) $officer->getKey() === (int) $preparer->getKey()) {
            throw new RuntimeException('You already prepare your own documents.');
        }
        if ($preparer->is_banned) {
            throw new RuntimeException('That account is not active.');
        }
        if ($expiresAt && $expiresAt <= $startsAt) {
            throw new RuntimeException('The end date must be after the start date.');
        }

        $overlap = PdtsUserDelegation::query()->preparing()
            ->where('principal_user_id', $officer->getKey())
            ->where('delegate_user_id', $preparer->getKey())
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $startsAt))
            ->when($expiresAt, fn ($q) => $q->where('starts_at', '<', $expiresAt))
            ->exists();
        if ($overlap) {
            throw new RuntimeException($preparer->name.' is already authorised for that period.');
        }

        $delegation = PdtsUserDelegation::create([
            'principal_user_id' => $officer->getKey(),
            'delegate_user_id' => $preparer->getKey(),
            'can_receive' => false,
            'can_prepare' => true,
            'granted_by' => $grantedBy->getKey(),
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'reason' => $reason ? trim($reason) : null,
        ]);

        $officer->signatureEvents()->create([
            'actor_id' => $grantedBy->getKey(),
            'action' => 'preparer_granted',
            'details' => ['preparer_id' => $preparer->getKey(), 'preparer' => $preparer->name, 'until' => $expiresAt?->format('Y-m-d')],
            'ip_address' => request()?->ip(),
            'occurred_at' => now(),
        ]);

        return $delegation;
    }

    /**
     * Ends an authorisation at once: by the officer, by the person who held
     * it (giving it up), or by an administrator.
     */
    public function revoke(PdtsUserDelegation $delegation, User $by): void
    {
        $isOfficer = (int) $delegation->principal_user_id === (int) $by->getKey();
        $isPreparer = (int) $delegation->delegate_user_id === (int) $by->getKey();
        if (! $isOfficer && ! $isPreparer && ! $by->can('delegations.manage') && ! $by->can('users.manage')) {
            throw new RuntimeException('You cannot change this authorisation.');
        }
        if ($delegation->revoked_at) {
            return;
        }

        $delegation->update(['revoked_at' => now()]);
        $delegation->principal?->signatureEvents()->create([
            'actor_id' => $by->getKey(),
            'action' => $isPreparer ? 'preparer_gave_up' : 'preparer_revoked',
            'details' => ['preparer_id' => $delegation->delegate_user_id, 'preparer' => $delegation->delegate?->name],
            'ip_address' => request()?->ip(),
            'occurred_at' => now(),
        ]);
    }

    /** Active authorisations to prepare documents. */
    private function authorisations(): Builder
    {
        return PdtsUserDelegation::query()->activeAt()->preparing();
    }
}
