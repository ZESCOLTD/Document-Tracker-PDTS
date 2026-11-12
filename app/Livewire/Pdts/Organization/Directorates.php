<?php

namespace App\Livewire\Pdts\Organization;

use App\Models\PdtsDirectorate;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Directorates are the top of the organisation: Directorate → Department → Office.
 */
class Directorates extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public string $search = '';
    public ?int $editingId = null;
    public string $code = '';
    public string $name = '';
    public bool $isActive = true;

    // The add/edit form is shown in a pop-up.
    public bool $showForm = false;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    protected function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:64', Rule::unique('pdts_directorates', 'code')->ignore($this->editingId)],
            'name' => ['required', 'string', 'max:191', Rule::unique('pdts_directorates', 'name')->ignore($this->editingId)],
            'isActive' => ['boolean'],
        ];
    }

    public function openCreate(): void
    {
        $this->cancelEdit();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->resetValidation();
        $this->showForm = true;
        $directorate = PdtsDirectorate::findOrFail($id);
        $this->editingId = $directorate->id;
        $this->code = $directorate->code ?? '';
        $this->name = $directorate->name;
        $this->isActive = $directorate->is_active;
    }

    public function cancelEdit(): void
    {
        $this->showForm = false;
        $this->reset(['editingId', 'code', 'name']);
        $this->isActive = true;
        $this->resetValidation();
    }

    public function save(): void
    {
        $validated = $this->validate();

        $directorate = $this->editingId
            ? PdtsDirectorate::findOrFail($this->editingId)
            : new PdtsDirectorate();

        if (! $validated['isActive'] && $directorate->exists && $this->hasActiveUnits($directorate)) {
            $this->addError('isActive', 'Deactivate this directorate’s active departments and offices first.');
            return;
        }

        $directorate->fill([
            'code' => $validated['code'] ?: null,
            'name' => $validated['name'],
            'is_active' => $validated['isActive'],
        ])->save();

        session()->flash('success', $this->editingId ? 'Directorate updated.' : 'Directorate created.');
        $this->cancelEdit();
    }

    public function toggleActive(int $id): void
    {
        $directorate = PdtsDirectorate::findOrFail($id);
        if ($directorate->is_active && $this->hasActiveUnits($directorate)) {
            session()->flash('error', 'Deactivate this directorate’s active departments and offices first.');
            return;
        }

        $directorate->update(['is_active' => ! $directorate->is_active]);
    }

    private function hasActiveUnits(PdtsDirectorate $directorate): bool
    {
        return $directorate->departments()->where('is_active', true)->exists()
            || $directorate->offices()->where('is_active', true)->exists();
    }

    public function render()
    {
        return view('livewire.pdts.organization.directorates', [
            'directorates' => PdtsDirectorate::query()
                ->withCount('departments')
                ->when($this->search !== '', fn ($query) => $query->where(function ($query) {
                    $query->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('code', 'like', '%'.$this->search.'%');
                }))
                ->orderBy('name')
                ->paginate(15),
        ])->layout('layouts.master', ['title' => 'Directorates']);
    }
}
