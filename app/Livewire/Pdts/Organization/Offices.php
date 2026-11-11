<?php

namespace App\Livewire\Pdts\Organization;

use App\Models\PdtsDepartment;
use App\Models\PdtsOffice;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class Offices extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public string $search = '';
    public ?int $editingId = null;
    public string $code = '';
    public string $name = '';
    public string $departmentId = '';
    public string $parentOfficeId = '';
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
            'code' => ['nullable', 'string', 'max:64', Rule::unique('pdts_offices', 'code')->ignore($this->editingId)],
            'name' => [
                'required',
                'string',
                'max:191',
                Rule::unique('pdts_offices', 'name')->where('department_id', $this->departmentId)->ignore($this->editingId),
            ],
            'departmentId' => ['required', 'integer', Rule::exists('pdts_departments', 'id')->where('is_active', true)],
            'parentOfficeId' => ['nullable', 'integer', Rule::exists('pdts_offices', 'id')],
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
        $office = PdtsOffice::findOrFail($id);
        $this->editingId = $office->id;
        $this->code = $office->code ?? '';
        $this->name = $office->name;
        $this->departmentId = (string) $office->department_id;
        $this->parentOfficeId = (string) ($office->parent_office_id ?? '');
        $this->isActive = $office->is_active;
    }

    public function cancelEdit(): void
    {
        $this->showForm = false;
        $this->reset(['editingId', 'code', 'name', 'departmentId', 'parentOfficeId']);
        $this->isActive = true;
        $this->resetValidation();
    }

    public function save(): void
    {
        $validated = $this->validate();
        $departmentId = (int) $validated['departmentId'];
        $parentId = $validated['parentOfficeId'] !== '' ? (int) $validated['parentOfficeId'] : null;

        if ($parentId) {
            $parent = PdtsOffice::findOrFail($parentId);
            if ($parent->department_id !== $departmentId) {
                $this->addError('parentOfficeId', 'Parent office must belong to the selected department.');
                return;
            }
            if ($this->editingId && $parent->id === $this->editingId) {
                $this->addError('parentOfficeId', 'An office cannot be its own parent.');
                return;
            }
            if ($this->editingId && $this->wouldCreateCycle($parentId)) {
                $this->addError('parentOfficeId', 'This parent would create an office cycle.');
                return;
            }
        }

        $office = $this->editingId ? PdtsOffice::findOrFail($this->editingId) : new PdtsOffice();
        if (! $validated['isActive'] && $office->exists && $this->hasActiveDependents($office)) {
            $this->addError('isActive', 'End active child-office and employee assignments before deactivating this office.');
            return;
        }

        $office->fill([
            'code' => $validated['code'] ?: null,
            'name' => $validated['name'],
            'department_id' => $departmentId,
            'parent_office_id' => $parentId,
            'is_active' => $validated['isActive'],
        ])->save();

        session()->flash('success', $this->editingId ? 'Office updated.' : 'Office created.');
        $this->cancelEdit();
    }

    public function toggleActive(int $id): void
    {
        $office = PdtsOffice::findOrFail($id);
        if ($office->is_active && $this->hasActiveDependents($office)) {
            session()->flash('error', 'End active child-office and employee assignments before deactivating this office.');
            return;
        }

        $office->update(['is_active' => ! $office->is_active]);
    }

    private function hasActiveDependents(PdtsOffice $office): bool
    {
        return $office->children()->where('is_active', true)->exists()
            || $office->assignments()->whereNull('ends_at')->exists();
    }

    private function wouldCreateCycle(int $parentId): bool
    {
        $ancestor = PdtsOffice::find($parentId);
        while ($ancestor) {
            if ($ancestor->id === $this->editingId) {
                return true;
            }
            $ancestor = $ancestor->parent;
        }

        return false;
    }

    public function render()
    {
        return view('livewire.pdts.organization.offices', [
            'offices' => PdtsOffice::query()
                ->with(['department', 'parent'])
                ->withCount(['assignments' => fn ($query) => $query->whereNull('ends_at')])
                ->when($this->search !== '', fn ($query) => $query->where(function ($query) {
                    $query->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('code', 'like', '%'.$this->search.'%')
                        ->orWhereHas('department', fn ($department) => $department->where('name', 'like', '%'.$this->search.'%'));
                }))
                ->orderBy('name')
                ->paginate(15),
            'departments' => PdtsDepartment::query()->where('is_active', true)->orderBy('name')->get(),
            'parentOffices' => PdtsOffice::query()
                ->where('is_active', true)
                ->when($this->departmentId !== '', fn ($query) => $query->where('department_id', $this->departmentId))
                ->when($this->editingId, fn ($query) => $query->whereKeyNot($this->editingId))
                ->orderBy('name')
                ->get(),
        ])->layout('layouts.master', ['title' => 'Offices']);
    }
}
