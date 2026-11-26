<?php

namespace App\Services\Documents;

use App\Models\PdtsDocument;
use App\Models\PdtsUserDelegation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may see a document: the people involved in it, and those who oversee
 * everything (Management / Auditor and administrators, by `audit.view`).
 *
 * Involved: who registered or prepared it; the officer who signs or issues it;
 * the person it is for (TO) and those copied (CC); who holds it now; anyone who
 * sent, received or delivered it; and an active delegate of someone it was sent
 * to. A draft is seen only by who prepares it and the officer who signs it:
 * the person it is for and the copies see it once it is registered.
 *
 * One rule for the list, the document page, its files and its movement history.
 */
class DocumentAccess
{
    /** Management / Auditor (audit.view) and administrators, who also cancel documents issued in error. */
    public static function seesEverything(User $user): bool
    {
        return $user->can('audit.view') || $user->can('documents.cancel');
    }

    public function canView(PdtsDocument $document, User $user): bool
    {
        if (self::seesEverything($user)) {
            return true;
        }

        return PdtsDocument::query()->whereKey($document->getKey())->tap(fn (Builder $q) => $this->onlyInvolved($q, $user))->exists();
    }

    /** The documents a user may see, for any list. */
    public function visibleTo(Builder $query, User $user): Builder
    {
        return self::seesEverything($user) ? $query : $this->onlyInvolved($query, $user);
    }

    /** The documents the user is involved in, whoever they are. */
    public function onlyInvolved(Builder $query, User $user): Builder
    {
        $id = (int) $user->getKey();
        $table = $query->getModel()->getTable();

        return $query->where(fn (Builder $q) => $q
            // Preparing and signing: drafts too.
            ->where("{$table}.registered_by", $id)
            ->orWhere("{$table}.signatory_id", $id)
            // Everyone else, once it is registered.
            ->orWhere(fn (Builder $registered) => $registered
                ->where("{$table}.status", '!=', 'draft')
                ->where(fn (Builder $people) => $people
                    ->where("{$table}.current_custodian_id", $id)
                    ->orWhere("{$table}.recipient_user_id", $id)
                    ->orWhereJsonContains("{$table}.cc_user_ids", $id)
                    ->orWhereJsonContains("{$table}.cc_user_ids", (string) $id)
                    ->orWhereHas('movements', fn (Builder $movements) => $movements
                        ->where(fn (Builder $m) => $m->where('sender_id', $id)->orWhere('recipient_id', $id)->orWhere('messenger_id', $id)))
                    // Receiving on behalf of someone it was sent to.
                    ->orWhereHas('movements', fn (Builder $movements) => $movements
                        ->whereIn('recipient_id', PdtsUserDelegation::query()->activeAt()->receiving()
                            ->where('delegate_user_id', $id)->select('principal_user_id'))))));
    }
}
