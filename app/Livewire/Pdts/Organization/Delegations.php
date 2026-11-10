<?php

namespace App\Livewire\Pdts\Organization;

use App\Models\PdtsUserDelegation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class Delegations extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public string $search = '';
    public string $principalUserId = '';
    public string $delegateUserId = '';
    public string $startsAt = '';
    public string $expiresAt = '';
    public string $reason = '';
    public bool $showHistory = false;

    public function mount(): void
    {
        $this->startsAt = now()->format('Y-m-d\TH:i');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    protected function rules(): array
    {
        return [
            'principalUserId' => ['required', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query->where('is_banned', false))],
            'delegateUserId' => ['required', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query->where('is_banned', false))],
            'startsAt' => ['required', 'date'],
            'expiresAt' => ['nullable', 'date', 'after:startsAt'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function grant(): void
    {
        $validated = $this->validate();
        $principalId = (int) $validated['principalUserId'];
        $delegateId = (int) $validated['delegateUserId'];

        if ($principalId === $delegateId) {
            $this->addError('delegateUserId', 'A user cannot delegate acknowledgement to themselves.');
            return;
        }

        $delegate = User::findOrFail($delegateId);
        if (! $delegate->can('documents.acknowledge')) {
            $this->addError('delegateUserId', 'The delegate must have permission to acknowledge documents.');
            return;
        }

        $startsAt = now()->parse($validated['startsAt']);
        $expiresAt = filled($validated['expiresAt']) ? now()->parse($validated['expiresAt']) : null;

        $overlap = PdtsUserDelegation::query()
            ->where('principal_user_id', $principalId)
            ->where('delegate_user_id', $delegateId)
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $startsAt))
            ->when($expiresAt, fn ($query) => $query->where('starts_at', '<', $expiresAt))
            ->exists();

        if ($overlap) {
            $this->addError('delegateUserId', 'An overlapping delegation already exists for this pair of users.');
            return;
        }

        PdtsUserDelegation::create([
            'principal_user_id' => $principalId,
            'delegate_user_id' => $delegateId,
            'granted_by' => auth()->id(),
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'reason' => filled($validated['reason']) ? trim($validated['reason']) : null,
        ]);

        session()->flash('success', 'Acknowledgement delegation created.');
        $this->reset(['principalUserId', 'delegateUserId', 'expiresAt', 'reason']);
        $this->startsAt = now()->format('Y-m-d\TH:i');
    }

    public function revoke(int $id): void
    {
        $delegation = PdtsUserDelegation::query()->whereNull('revoked_at')->findOrFail($id);
        $delegation->update(['revoked_at' => now()]);
        session()->flash('success', 'Delegation revoked. Its history has been retained.');
    }

    public function render()
    {
        $delegations = PdtsUserDelegation::query()
            ->with(['principal', 'delegate', 'granter'])
            ->when(! $this->showHistory, fn ($query) => $query->whereNull('revoked_at'))
            ->when($this->search !== '', fn ($query) => $query->where(function ($query) {
                $query->whereHas('principal', fn ($user) => $user
                    ->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('man_no', 'like', '%'.$this->search.'%'))
                    ->orWhereHas('delegate', fn ($user) => $user
                        ->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('man_no', 'like', '%'.$this->search.'%'));
            }))
            ->orderByDesc('starts_at')
            ->paginate(15);

        return view('livewire.pdts.organization.delegations', [
            'delegations' => $delegations,
            'users' => User::query()->where('is_banned', false)->orderBy('name')->get(['id', 'name', 'man_no']),
        ])->layout('layouts.master', ['title' => 'Acknowledgement Delegations']);
    }
}
