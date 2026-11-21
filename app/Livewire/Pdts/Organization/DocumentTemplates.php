<?php

namespace App\Livewire\Pdts\Organization;

use App\Models\PdtsDocumentTemplate;
use App\Models\PdtsDocumentType;
use App\Services\Pdts\Templates\DocxTemplateFiller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Word templates internal documents are generated from. Templates already used
 * by documents are deactivated rather than replaced, so those documents keep
 * the version they were made from; uploading a new file adds a new template.
 */
class DocumentTemplates extends Component
{
    use WithFileUploads;

    public ?int $editingId = null;

    public string $name = '';

    public string $documentTypeId = '';

    public bool $isActive = true;

    public $file;

    public bool $showForm = false;

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'documentTypeId' => ['nullable', 'integer', Rule::exists('pdts_document_types', 'id')],
            'isActive' => ['boolean'],
            'file' => [$this->editingId ? 'nullable' : 'required', 'file', 'mimes:docx', 'max:10240'],
        ];
    }

    protected function messages(): array
    {
        return [
            'file.required' => 'Choose the Word template (.docx) to upload.',
            'file.mimes' => 'The template must be a Word .docx file.',
        ];
    }

    public function openCreate(): void
    {
        $this->cancelEdit();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $template = PdtsDocumentTemplate::findOrFail($id);
        $this->cancelEdit();
        $this->showForm = true;
        $this->editingId = $template->id;
        $this->name = $template->name;
        $this->documentTypeId = (string) ($template->document_type_id ?? '');
        $this->isActive = $template->is_active;
    }

    public function cancelEdit(): void
    {
        $this->showForm = false;
        $this->reset(['editingId', 'name', 'documentTypeId', 'file']);
        $this->isActive = true;
        $this->resetValidation();
    }

    public function save(DocxTemplateFiller $filler): void
    {
        $validated = $this->validate();
        $template = $this->editingId ? PdtsDocumentTemplate::findOrFail($this->editingId) : new PdtsDocumentTemplate;

        if ($this->file && $template->is_builtin) {
            $this->addError('file', 'The built-in memorandum cannot be replaced. Upload a new template instead.');

            return;
        }

        if ($this->file) {
            try {
                $placeholders = $filler->placeholdersIn($this->file->getRealPath());
            } catch (Throwable) {
                $this->addError('file', 'This file could not be read as a Word document.');

                return;
            }

            if (! in_array('qr_code', $placeholders, true)) {
                $this->addError('file', 'The template must contain ${qr_code} (normally in the footer) so the printed document carries its QR code.');

                return;
            }

            if ($template->exists && $template->documents()->exists()) {
                $this->addError('file', 'Documents were made from this template. Deactivate it and upload a new template instead.');

                return;
            }

            $oldPath = $template->storage_path;
            $template->storage_path = $this->file->store('pdts/templates', 'private');
            $template->original_filename = basename($this->file->getClientOriginalName());
            $template->uploaded_by = Auth::id();

            if ($oldPath) {
                Storage::disk('private')->delete($oldPath);
            }
        }

        $template->fill([
            'name' => trim($validated['name']),
            'document_type_id' => $validated['documentTypeId'] !== '' ? (int) $validated['documentTypeId'] : null,
            'is_active' => $validated['isActive'],
        ])->save();

        session()->flash('success', $this->editingId ? 'Template updated.' : 'Template added.');
        $this->cancelEdit();
    }

    public function toggleActive(int $id): void
    {
        $template = PdtsDocumentTemplate::findOrFail($id);
        $template->update(['is_active' => ! $template->is_active]);
    }

    public function delete(int $id): void
    {
        $template = PdtsDocumentTemplate::withCount('documents')->findOrFail($id);

        if ($template->is_builtin) {
            session()->flash('error', 'The built-in memorandum cannot be deleted. Deactivate it instead.');

            return;
        }

        if ($template->documents_count > 0) {
            session()->flash('error', "\"{$template->name}\" was used for {$template->documents_count} ".str('document')->plural($template->documents_count).'. Deactivate it instead.');

            return;
        }

        if ($template->storage_path) {
            Storage::disk('private')->delete($template->storage_path);
        }
        $template->delete();
        session()->flash('success', 'Template deleted.');
    }

    public function render()
    {
        return view('livewire.pdts.organization.document-templates', [
            'templates' => PdtsDocumentTemplate::query()
                ->with(['documentType', 'uploader'])
                ->withCount('documents')
                ->orderByDesc('is_builtin')
                ->orderBy('name')
                ->get(),
            'documentTypes' => PdtsDocumentType::query()->orderBy('name')->get(),
            'placeholders' => PdtsDocumentTemplate::PLACEHOLDERS,
        ])->layout('layouts.master', ['title' => 'Document Templates']);
    }
}
