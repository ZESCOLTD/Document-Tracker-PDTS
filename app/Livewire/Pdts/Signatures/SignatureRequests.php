<?php

namespace App\Livewire\Pdts\Signatures;

use App\Models\PdtsDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Documents prepared on someone's behalf, from both sides:
 *  - for the officer who signs: waiting for me, returned by me, signed by me;
 *  - for the secretary who prepares: sent for signature, returned to me, signed.
 */
class SignatureRequests extends Component
{
    #[Url]
    public string $tab = '';

    public const TABS = [
        'waiting' => ['Waiting for me', 'fa-signature'],
        'returned_by_me' => ['Returned by me', 'fa-undo'],
        'signed_by_me' => ['Signed by me', 'fa-check-circle'],
        'sent' => ['Sent for signature', 'fa-paper-plane'],
        'returned_to_me' => ['Returned to me', 'fa-reply'],
        'prepared_signed' => ['Prepared by me, signed', 'fa-check-double'],
    ];

    public static function query(string $tab, int $userId): Builder
    {
        $onBehalf = fn (Builder $q) => $q->whereNotNull('signatory_id')->whereColumn('signatory_id', '!=', 'registered_by');
        $draft = fn (Builder $q) => $q->where('status', 'draft');

        $query = PdtsDocument::query()->where('origin', 'internal');

        return match ($tab) {
            'waiting' => $query->where('signatory_id', $userId)->tap($onBehalf)->tap($draft)->where('signature_status', PdtsDocument::SIGNATURE_AWAITING),
            'returned_by_me' => $query->where('signatory_id', $userId)->tap($onBehalf)->tap($draft)->where('signature_status', PdtsDocument::SIGNATURE_RETURNED),
            'signed_by_me' => $query->whereHas('electronicSignature', fn ($s) => $s->where('user_id', $userId)),
            'sent' => $query->where('registered_by', $userId)->tap($onBehalf)->tap($draft)->where('signature_status', PdtsDocument::SIGNATURE_AWAITING),
            'returned_to_me' => $query->where('registered_by', $userId)->tap($onBehalf)->tap($draft)->where('signature_status', PdtsDocument::SIGNATURE_RETURNED),
            'prepared_signed' => $query->where('registered_by', $userId)->tap($onBehalf)->where('signature_status', PdtsDocument::SIGNATURE_SIGNED),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** What needs this person's action: drafts to sign, and drafts returned to them. */
    public static function actionCount(int $userId): int
    {
        return self::query('waiting', $userId)->count() + self::query('returned_to_me', $userId)->count();
    }

    public function render()
    {
        $userId = (int) Auth::id();
        $counts = collect(self::TABS)->map(fn ($tab, $key) => self::query($key, $userId)->count());

        if (! isset(self::TABS[$this->tab])) {
            $this->tab = $counts->filter()->keys()->first() ?? 'waiting';
        }

        return view('livewire.pdts.signatures.signature-requests', [
            'counts' => $counts,
            'documents' => self::query($this->tab, $userId)
                ->with(['registrar', 'signatory', 'documentType', 'electronicSignature'])
                ->latest('updated_at')
                ->limit(100)
                ->get(),
        ])->layout('layouts.master', ['title' => 'Signatures']);
    }
}
