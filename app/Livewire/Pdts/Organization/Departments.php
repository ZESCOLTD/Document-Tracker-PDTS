<?php

namespace App\Livewire\Pdts\Organization;

use App\Models\PdtsDepartment;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class Departments extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public string $search = '';
    public ?int $editingId = null;
    public string $code = '';
    public string $name = '';
    public string $parentDepartmentId = '';
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
            'code' => ['nullable', 'string', 'max:64', Rule::unique('pdts_departments', 'code')->ignore($this->editingId)],
            'name' => ['required', 'string', 'max:191', Rule::unique('pdts_departments', 'name')->ignore($this->editingId)],
            'parentDepartmentId' => ['nullable', 'integer', Rule::exists('pdts_departments', 'id')],
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
        $department = PdtsDepartment::findOrFail($id);
        $this->editingId = $department->id;
        $this->code = $department->code ?? '';
        $this->name = $department->name;
        $this->parentDepartmentId = (string) ($department->parent_department_id ?? '');
        $this->isActive = $department->is_active;
    }

    public function cancelEdit(): void
    {
        $this->showForm = false;
        $this->reset(['editingId', 'code', 'name', 'parentDepartmentId']);
        $this->isActive = true;
        $this->resetValidation();
    }

    public function save(): void
    {
        $validated = $this->validate();
        $parentId = $validated['parentDepartmentId'] !== '' ? (int) $validated['parentDepartmentId'] : null;

        if ($this->editingId && $parentId === $this->editingId) {
            $this->addError('parentDepartmentId', 'A department cannot be its own parent.');
            return;
        }

        if ($this->editingId && $parentId && $this->wouldCreateCycle($parentId)) {
            $this->addError('parentDepartmentId', 'This parent would create a department cycle.');
            return;
        }

        $department = $this->editingId
            ? PdtsDepartment::findOrFail($this->editingId)
            : new PdtsDepartment();

        if (! $validated['isActive'] && $department->exists && $this->hasActiveChildren($department)) {
            $this->addError('isActive', 'Deactivate this department’s active child departments and offices first.');
            return;
        }

        $department->fill([
            'code' => $validated['code'] ?: null,
            'name' => $validated['name'],
            'parent_department_id' => $parentId,
            'is_active' => $validated['isActive'],
        ])->save();

        session()->flash('success', $this->editingId ? 'Department updated.' : 'Department created.');
        $this->cancelEdit();
    }

    public function toggleActive(int $id): void
    {
        $department = PdtsDepartment::findOrFail($id);
        if ($department->is_active && $this->hasActiveChildren($department)) {
            session()->flash('error', 'Deactivate this department’s active child departments and offices first.');
            return;
        }

        $department->update(['is_active' => ! $department->is_active]);
    }

    private function hasActiveChildren(PdtsDepartment $department): bool
    {
        return $department->children()->where('is_active', true)->exists()
            || $department->offices()->where('is_active', true)->exists();
    }

    private function wouldCreateCycle(int $parentId): bool
    {
        $ancestor = PdtsDepartment::find($parentId);
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
        return view('livewire.pdts.organization.departments', [
            'departments' => PdtsDepartment::query()
                ->with('parent')
                ->withCount(['children', 'offices'])
                ->when($this->search !== '', fn ($query) => $query->where(function ($query) {
                    $query->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('code', 'like', '%'.$this->search.'%');
                }))
                ->orderBy('name')
                ->paginate(15),
            'parentOptions' => PdtsDepartment::query()
                ->where('is_active', true)
                ->when($this->editingId, fn ($query) => $query->whereKeyNot($this->editingId))
                ->orderBy('name')
                ->get(),
        ])->layout('layouts.master', ['title' => 'Departments']);
    }
}
