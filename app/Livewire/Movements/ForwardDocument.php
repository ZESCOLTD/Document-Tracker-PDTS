<?php

namespace App\Livewire\Movements;

use App\Enums\MovementStatus;
use App\Models\PdtsDocument;
use App\Models\User;
use App\Services\Documents\CustodyException;
use App\Services\Documents\CustodyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Forward button and form. Can be embedded on any page that shows a document:
 * <livewire:movements.forward-document :document="$document" />
 */
class ForwardDocument extends Component
{
    #[Locked]
    public int $documentId;

    #[Locked]
    public bool $showTrigger = true;

    /** True on a page that already shows where the document is going. */
    public bool $quiet = false;

    public bool $open = false;

    public string $search = '';

    public ?int $recipientId = null;

    public ?int $messengerId = null;

    public string $remarks = '';

    public ?string $notice = null;

    public ?string $errorMessage = null;

    public function mount(PdtsDocument $document): void
    {
        $this->documentId = $document->getKey();
    }

    #[On('open-forward.{documentId}')]
    public function openForm(CustodyService $custody): void
    {
        $this->resetForm();
        $this->reset(['notice', 'errorMessage']);

        // The person the document is for, when they do not have it yet: chosen already (it can be changed).
        $document = PdtsDocument::find($this->documentId);
        $suggested = $document ? $this->suggestedRecipient($document) : null;
        if ($suggested && $custody->eligibleRecipients(Auth::user())->whereKey($suggested->id)->exists()) {
            $this->recipientId = $suggested->id;
            $this->remarks = 'For your attention';
        }

        $this->open = true;
    }

    /**
     * The person an internal document is for (its TO person in PDTS), while
     * the paper has not reached them yet; null otherwise.
     */
    public function suggestedRecipient(PdtsDocument $document): ?User
    {
        $recipient = $document->recipient_user_id ? User::find($document->recipient_user_id) : null;
        if (! $recipient || $document->isDraft() || (int) $recipient->id === (int) Auth::id() || (int) $document->current_custodian_id === (int) $recipient->id) {
            return null;
        }
        $reached = $document->movements()->where('recipient_id', $recipient->id)
            ->whereIn('status', [MovementStatus::Acknowledged->value, MovementStatus::AwaitingReceipt->value])->exists();

        return $reached ? null : $recipient;
    }

    public function closeForm(): void
    {
        $this->resetForm();
        $this->open = false;
    }

    public function selectRecipient(int $recipientId): void
    {
        $this->recipientId = $recipientId;
        $this->messengerId = null;
        $this->search = '';
    }

    public function clearRecipient(): void
    {
        $this->recipientId = null;
        $this->messengerId = null;
    }

    public function forward(CustodyService $custody): void
    {
        $this->validate([
            'recipientId' => ['required', 'integer'],
            'messengerId' => ['nullable', 'integer'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ], ['recipientId.required' => 'Choose who you are forwarding this document to.']);

        $document = PdtsDocument::find($this->documentId);
        $recipient = User::withTrashed()->find($this->recipientId);

        try {
            if (! $document || ! $recipient) {
                throw new CustodyException('This document or recipient is no longer available.');
            }

            $messenger = $this->messengerId ? User::find($this->messengerId) : null;
            if ($this->messengerId && ! $messenger) {
                throw new CustodyException('This messenger is no longer available.');
            }

            $custody->forward($document, Auth::user(), $recipient, $this->remarks, $messenger);

            // Where something else on the page says it (the document page's bar), no note of its own.
            $this->notice = match (true) {
                $this->quiet => null,
                $messenger !== null => "Pickup assigned to {$messenger->name} for delivery to {$recipient->name}. You remain the custodian until pickup is confirmed.",
                default => "Forwarded to {$recipient->name}. You remain the custodian until they acknowledge.",
            };
            $this->errorMessage = null;
            $this->closeForm();
            $this->dispatch('handover-changed');
        } catch (CustodyException $exception) {
            $this->errorMessage = $exception->getMessage();
        }
    }

    public function render(CustodyService $custody)
    {
        $user = Auth::user();
        $document = PdtsDocument::find($this->documentId);

        $suggested = $document ? $this->suggestedRecipient($document) : null;

        return view('livewire.movements.forward-document', [
            'canForward' => $document && $custody->forwardBlockedReason($document, $user) === null,
            'document' => $document,
            'suggested' => $suggested,
            'selectedRecipient' => $this->recipientId ? $custody->eligibleRecipients($user)->find($this->recipientId) : null,
            'matches' => $this->open && ! $this->recipientId ? $this->searchRecipients($custody) : collect(),
            'messengers' => $this->open && $this->recipientId
                ? $custody->eligibleMessengers($user, $this->recipientId ? User::find($this->recipientId) : null)->orderBy('name')->get()
                : collect(),
        ]);
    }

    private function searchRecipients(CustodyService $custody)
    {
        $term = mb_strtolower(trim($this->search));

        if (mb_strlen($term) < 2) {
            return collect();
        }

        return $custody->eligibleRecipients(Auth::user())
            ->where(fn (Builder $query) => $query
                ->where(DB::raw('lower(name)'), 'like', "%{$term}%")
                ->orWhere(DB::raw('lower(man_no)'), 'like', "%{$term}%")
                ->orWhere(DB::raw('lower(job_title)'), 'like', "%{$term}%"))
            ->orderBy('name')
            ->limit(10)
            ->get(['users.id', 'name', 'man_no', 'job_title']);
    }

    private function resetForm(): void
    {
        $this->reset(['search', 'recipientId', 'messengerId', 'remarks']);
        $this->resetValidation();
    }
}
