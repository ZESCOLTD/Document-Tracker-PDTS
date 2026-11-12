<?php

namespace App\Livewire\Pdts\Organization;

use App\Models\PdtsDepartment;
use App\Models\PdtsDirectorate;
use App\Models\PdtsOffice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * An office sits either directly under a directorate (no department, e.g. the
 * Director's office) or under a department. One office per unit can be its head.
 */
class Offices extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public string $search = '';
    public ?int $editingId = null;
    public string $code = '';
    public string $name = '';
    public string $directorateId = '';
    public string $departmentId = '';
    public string $parentOfficeId = '';
    public bool $isHead = false;
    public bool $isActive = true;

    // The add/edit form is shown in a pop-up.
    public bool $showForm = false;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedDirectorateId(): void
    {
        $this->departmentId = '';
        $this->parentOfficeId = '';
        $this->resetValidation(['departmentId', 'parentOfficeId']);
    }

    public function updatedDepartmentId(): void
    {
        $this->parentOfficeId = '';
        $this->resetValidation('parentOfficeId');
    }

    protected function rules(): array
    {
        $directorateLevel = $this->departmentId === '';

        return [
            'code' => ['nullable', 'string', 'max:64', Rule::unique('pdts_offices', 'code')->ignore($this->editingId)],
            'name' => [
                'required',
                'string',
                'max:191',
                $directorateLevel
                    ? Rule::unique('pdts_offices', 'name')->where('directorate_id', (int) $this->directorateId)->ignore($this->editingId)
                    : Rule::unique('pdts_offices', 'name')->where('department_id', (int) $this->departmentId)->ignore($this->editingId),
            ],
            'directorateId' => ['required', 'integer', Rule::exists('pdts_directorates', 'id')->where('is_active', true)],
            'departmentId' => [
                'nullable',
                'integer',
                Rule::exists('pdts_departments', 'id')
                    ->where('is_active', true)
                    ->where('directorate_id', (int) $this->directorateId),
            ],
            'parentOfficeId' => ['nullable', 'integer', Rule::exists('pdts_offices', 'id')],
            'isHead' => ['boolean'],
            'isActive' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return [
            'directorateId.required' => 'Choose the directorate this office is in.',
            'directorateId.exists' => 'Choose an active directorate.',
            'departmentId.exists' => 'Choose an active department in the selected directorate.',
            'name.unique' => 'There is already an office with this name here.',
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
        $office = PdtsOffice::with(['department', 'directorate'])->findOrFail($id);
        $this->editingId = $office->id;
        $this->code = $office->code ?? '';
        $this->name = $office->name;
        $this->directorateId = (string) ($office->owningDirectorate()?->id ?? '');
        $this->departmentId = (string) ($office->department_id ?? '');
        $this->parentOfficeId = (string) ($office->parent_office_id ?? '');
        $this->isHead = $office->is_head;
        $this->isActive = $office->is_active;
    }

    public function cancelEdit(): void
    {
        $this->showForm = false;
        $this->reset(['editingId', 'code', 'name', 'directorateId', 'departmentId', 'parentOfficeId', 'isHead']);
        $this->isActive = true;
        $this->resetValidation();
    }

    public function save(): void
    {
        $validated = $this->validate();
        $departmentId = $validated['departmentId'] !== '' && $validated['departmentId'] !== null ? (int) $validated['departmentId'] : null;
        $directorateId = $departmentId ? null : (int) $validated['directorateId'];
        $parentId = $validated['parentOfficeId'] !== '' ? (int) $validated['parentOfficeId'] : null;

        if ($parentId) {
            $parent = PdtsOffice::findOrFail($parentId);
            if ($parent->department_id !== $departmentId || $parent->directorate_id !== $directorateId) {
                $this->addError('parentOfficeId', $departmentId
                    ? 'Parent office must belong to the selected department.'
                    : 'Parent office must be another office of the directorate itself.');
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

        if ($validated['isHead'] && $this->otherHead($departmentId, $directorateId)) {
            $this->addError('isHead', ($departmentId ? 'This department' : 'This directorate').' already has a head office: '.$this->otherHead($departmentId, $directorateId)->name.'.');
            return;
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
            'directorate_id' => $directorateId,
            'parent_office_id' => $parentId,
            'is_head' => $validated['isHead'],
            'is_active' => $validated['isActive'],
        ])->save();

        session()->flash('success', $this->editingId ? 'Office updated.' : 'Office created.');
        $this->cancelEdit();
    }

    public function toggleActive(int $id): void
    {
        $office = PdtsOffice::with(['department.directorate', 'directorate'])->findOrFail($id);
        if ($office->is_active && $this->hasActiveDependents($office)) {
            session()->flash('error', 'End active child-office and employee assignments before deactivating this office.');
            return;
        }

        $unitActive = $office->owningDirectorate()?->is_active
            && ($office->isDirectorateLevel() || $office->department?->is_active);

        if (! $office->is_active && ! $unitActive) {
            session()->flash('error', 'Activate the office’s department and directorate first.');
            return;
        }

        $office->update(['is_active' => ! $office->is_active]);
    }

    private function otherHead(?int $departmentId, ?int $directorateId): ?PdtsOffice
    {
        return PdtsOffice::query()
            ->where('is_head', true)
            ->when($departmentId,
                fn (Builder $query) => $query->where('department_id', $departmentId),
                fn (Builder $query) => $query->whereNull('department_id')->where('directorate_id', $directorateId))
            ->when($this->editingId, fn (Builder $query) => $query->whereKeyNot($this->editingId))
            ->first();
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
        $like = '%'.$this->search.'%';

        return view('livewire.pdts.organization.offices', [
            'offices' => PdtsOffice::query()
                ->with(['department.directorate', 'directorate', 'parent'])
                ->withCount(['assignments' => fn ($query) => $query->whereNull('ends_at')])
                ->when($this->search !== '', fn ($query) => $query->where(function ($query) use ($like) {
                    $query->where('name', 'like', $like)
                        ->orWhere('code', 'like', $like)
                        ->orWhereHas('department', fn ($department) => $department->where('name', 'like', $like))
                        ->orWhereHas('department.directorate', fn ($directorate) => $directorate->where('name', 'like', $like))
                        ->orWhereHas('directorate', fn ($directorate) => $directorate->where('name', 'like', $like));
                }))
                ->orderBy('name')
                ->paginate(15),
            'directorates' => PdtsDirectorate::query()->where('is_active', true)->orderBy('name')->get(),
            'departments' => $this->directorateId === ''
                ? collect()
                : PdtsDepartment::query()
                    ->where('is_active', true)
                    ->where('directorate_id', (int) $this->directorateId)
                    ->orderBy('name')
                    ->get(),
            'parentOffices' => $this->directorateId === ''
                ? collect()
                : PdtsOffice::query()
                    ->where('is_active', true)
                    ->when($this->departmentId !== '',
                        fn ($query) => $query->where('department_id', (int) $this->departmentId),
                        fn ($query) => $query->whereNull('department_id')->where('directorate_id', (int) $this->directorateId))
                    ->when($this->editingId, fn ($query) => $query->whereKeyNot($this->editingId))
                    ->orderBy('name')
                    ->get(),
        ])->layout('layouts.master', ['title' => 'Offices']);
    }
}
