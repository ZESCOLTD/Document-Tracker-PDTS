<?php

namespace App\Livewire\Movements;

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
    public function openForm(): void
    {
        $this->resetForm();
        $this->reset(['notice', 'errorMessage']);
        $this->open = true;
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

            $this->notice = "Forwarded to {$recipient->name}. You remain the custodian until they acknowledge.";
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

        return view('livewire.movements.forward-document', [
            'canForward' => $document && $custody->forwardBlockedReason($document, $user) === null,
            'document' => $document,
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
