<?php

namespace App\Livewire\Pdts\Organization;

use App\Models\PdtsDocumentType;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The list of document types offered when registering a document.
 * Types in use are deactivated rather than deleted, so old documents keep theirs.
 */
class DocumentTypes extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public string $search = '';
    public ?int $editingId = null;
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
            'name' => ['required', 'string', 'max:120', Rule::unique('pdts_document_types', 'name')->ignore($this->editingId)],
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
        $type = PdtsDocumentType::findOrFail($id);
        $this->editingId = $type->id;
        $this->name = $type->name;
        $this->isActive = $type->is_active;
        $this->resetValidation();
    }

    public function cancelEdit(): void
    {
        $this->showForm = false;
        $this->reset(['editingId', 'name']);
        $this->isActive = true;
        $this->resetValidation();
    }

    public function save(): void
    {
        $validated = $this->validate();

        $type = $this->editingId ? PdtsDocumentType::findOrFail($this->editingId) : new PdtsDocumentType();
        $type->fill(['name' => trim($validated['name']), 'is_active' => $validated['isActive']])->save();

        session()->flash('success', $this->editingId ? 'Document type updated.' : 'Document type added.');
        $this->cancelEdit();
    }

    public function toggleActive(int $id): void
    {
        $type = PdtsDocumentType::findOrFail($id);
        $type->update(['is_active' => ! $type->is_active]);
    }

    public function delete(int $id): void
    {
        $type = PdtsDocumentType::withCount('documents')->findOrFail($id);

        if ($type->documents_count > 0) {
            session()->flash('error', "\"{$type->name}\" is used by {$type->documents_count} ".str('document')->plural($type->documents_count).'. Deactivate it instead.');

            return;
        }

        $type->delete();
        session()->flash('success', 'Document type deleted.');

        if ($this->editingId === $id) {
            $this->cancelEdit();
        }
    }

    public function render()
    {
        return view('livewire.pdts.organization.document-types', [
            'types' => PdtsDocumentType::query()
                ->withCount('documents')
                ->when($this->search !== '', fn ($query) => $query->where('name', 'like', '%'.$this->search.'%'))
                ->orderBy('name')
                ->paginate(15),
        ])->layout('layouts.master', ['title' => 'Document Types']);
    }
}
