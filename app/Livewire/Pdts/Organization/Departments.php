<?php

namespace App\Livewire\Pdts\Organization;

use App\Models\PdtsDepartment;
use App\Models\PdtsDirectorate;
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
    public string $directorateId = '';
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
            'directorateId' => ['required', 'integer', Rule::exists('pdts_directorates', 'id')->where('is_active', true)],
            'isActive' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return [
            'directorateId.required' => 'Choose the directorate this department belongs to.',
            'directorateId.exists' => 'Choose an active directorate.',
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
        $this->directorateId = (string) ($department->directorate_id ?? '');
        $this->isActive = $department->is_active;
    }

    public function cancelEdit(): void
    {
        $this->showForm = false;
        $this->reset(['editingId', 'code', 'name', 'directorateId']);
        $this->isActive = true;
        $this->resetValidation();
    }

    public function save(): void
    {
        $validated = $this->validate();

        $department = $this->editingId
            ? PdtsDepartment::findOrFail($this->editingId)
            : new PdtsDepartment();

        if (! $validated['isActive'] && $department->exists && $this->hasActiveOffices($department)) {
            $this->addError('isActive', 'Deactivate this department’s active offices first.');
            return;
        }

        $department->fill([
            'code' => $validated['code'] ?: null,
            'name' => $validated['name'],
            'directorate_id' => (int) $validated['directorateId'],
            'is_active' => $validated['isActive'],
        ])->save();

        session()->flash('success', $this->editingId ? 'Department updated.' : 'Department created.');
        $this->cancelEdit();
    }

    public function toggleActive(int $id): void
    {
        $department = PdtsDepartment::with('directorate')->findOrFail($id);

        if ($department->is_active && $this->hasActiveOffices($department)) {
            session()->flash('error', 'Deactivate this department’s active offices first.');
            return;
        }

        if (! $department->is_active && ! $department->directorate?->is_active) {
            session()->flash('error', 'Put this department under an active directorate before activating it.');
            return;
        }

        $department->update(['is_active' => ! $department->is_active]);
    }

    private function hasActiveOffices(PdtsDepartment $department): bool
    {
        return $department->offices()->where('is_active', true)->exists();
    }

    public function render()
    {
        return view('livewire.pdts.organization.departments', [
            'departments' => PdtsDepartment::query()
                ->with('directorate')
                ->withCount('offices')
                ->when($this->search !== '', fn ($query) => $query->where(function ($query) {
                    $query->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('code', 'like', '%'.$this->search.'%')
                        ->orWhereHas('directorate', fn ($directorate) => $directorate->where('name', 'like', '%'.$this->search.'%'));
                }))
                ->orderBy('name')
                ->paginate(15),
            'directorates' => PdtsDirectorate::query()->where('is_active', true)->orderBy('name')->get(),
        ])->layout('layouts.master', ['title' => 'Departments']);
    }
}
