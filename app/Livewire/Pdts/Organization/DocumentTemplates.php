<?php

namespace App\Livewire\Pdts\Organization;

use App\Models\PdtsDocumentTemplate;
use App\Models\PdtsDocumentType;
use App\Services\Pdts\Templates\TemplateSetup;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

/**
 * ZESCO's own templates (Word or PDF) internal documents are made from.
 *
 * 1. Upload: the file is kept exactly as uploaded.
 * 2. Place the QR code on a preview (TemplateQrSetup), then save.
 * A template is offered to authors only once its QR code has been placed.
 * Templates documents were made from cannot have their file replaced.
 */
class DocumentTemplates extends Component
{
    use WithFileUploads;

    public ?int $editingId = null;

    public string $name = '';

    public string $description = '';

    public string $documentTypeId = '';

    public bool $isActive = true;

    public $file;

    public bool $showForm = false;

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'documentTypeId' => ['nullable', 'integer', Rule::exists('pdts_document_types', 'id')],
            'isActive' => ['boolean'],
            'file' => [$this->editingId ? 'nullable' : 'required', 'file', 'mimes:docx,pdf', 'max:10240'],
        ];
    }

    protected function messages(): array
    {
        return [
            'file.required' => 'Choose the template file (Word .docx or PDF) to upload.',
            'file.mimes' => 'The template must be a Word .docx or a PDF file.',
            'file.max' => 'The template must be 10 MB or smaller.',
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
        $this->description = (string) $template->description;
        $this->documentTypeId = (string) ($template->document_type_id ?? '');
        $this->isActive = $template->is_active;
    }

    public function cancelEdit(): void
    {
        $this->showForm = false;
        $this->reset(['editingId', 'name', 'description', 'documentTypeId', 'file']);
        $this->isActive = true;
        $this->resetValidation();
    }

    /** Checks the chosen file straight away and suggests a name from it. */
    public function updatedFile(): void
    {
        $this->resetValidation('file');

        try {
            $this->validateOnly('file');
        } catch (ValidationException $e) {
            $this->file = null;

            throw $e;
        }

        if ($this->name === '') {
            $this->name = Str::of(pathinfo($this->file->getClientOriginalName(), PATHINFO_FILENAME))
                ->replace(['_', '-'], ' ')->squish()->limit(150, '')->toString();
        }
    }

    public function removeFile(): void
    {
        $this->reset('file');
        $this->resetValidation('file');
    }

    public function save(TemplateSetup $setup)
    {
        $validated = $this->validate();
        $template = $this->editingId ? PdtsDocumentTemplate::withCount('documents')->findOrFail($this->editingId) : new PdtsDocumentTemplate;

        // Documents were made from it: the new file becomes the next version, and this
        // one stays for those documents (it is retired once the new version is saved).
        if ($this->file && $template->exists && $template->documents_count > 0) {
            try {
                $stored = $setup->storeOriginal($this->file->getRealPath(), $this->file->getClientOriginalExtension());
            } catch (Throwable $e) {
                report($e);
                $this->addError('file', str_starts_with($e->getMessage(), 'This') ? $e->getMessage() : 'This file could not be read. Save it again from Word or as a PDF and try again.');

                return null;
            }

            $next = PdtsDocumentTemplate::create([
                'name' => trim($validated['name']),
                'version' => (int) $template->version + 1,
                'previous_version_id' => $template->id,
                'description' => trim((string) $validated['description']) ?: null,
                'document_type_id' => $validated['documentTypeId'] !== '' ? (int) $validated['documentTypeId'] : null,
                'kind' => $stored['kind'],
                'original_path' => $stored['path'],
                'original_filename' => basename($this->file->getClientOriginalName()),
                'page_count' => $stored['page_count'],
                // How the form looks carries over; marks belong to the old file's lines.
                'field_settings' => $template->field_settings,
                'is_active' => false,
                'uploaded_by' => Auth::id(),
            ]);

            $this->cancelEdit();
            session()->flash('success', "Version {$next->version} uploaded. Version {$template->version} stays in use until this one is saved. Now choose where the QR code goes.");

            return $this->redirectRoute('pdts.organization.document-templates.qr', $next);
        }

        $old = [];
        if ($this->file) {
            try {
                $stored = $setup->storeOriginal($this->file->getRealPath(), $this->file->getClientOriginalExtension());
            } catch (Throwable $e) {
                report($e);
                $this->addError('file', str_starts_with($e->getMessage(), 'This') ? $e->getMessage() : 'This file could not be read. Save it again from Word or as a PDF and try again.');

                return null;
            }

            $old = array_filter([$template->original_path, $template->storage_path]);
            // A new file starts again: its QR code has to be placed.
            $template->forceFill([
                // A file replaces whatever it was built from.
                'source' => 'upload',
                'design' => null,
                'kind' => $stored['kind'],
                'original_path' => $stored['path'],
                'storage_path' => null,
                'original_filename' => basename($this->file->getClientOriginalName()),
                'page_count' => $stored['page_count'],
                'placeholders' => null,
                'qr_page' => null,
                'qr_x_mm' => null,
                'qr_y_mm' => null,
                'qr_size_mm' => null,
                'configured_at' => null,
                'uploaded_by' => Auth::id(),
            ]);
        }

        unset($template->documents_count);
        $template->fill([
            'name' => trim($validated['name']),
            'description' => trim((string) $validated['description']) ?: null,
            'document_type_id' => $validated['documentTypeId'] !== '' ? (int) $validated['documentTypeId'] : null,
            // Only a template whose QR code has been placed, and that can be signed
            // in PDTS or is marked as not signed, can be offered to authors.
            'is_active' => $validated['isActive'] && $template->configured_at !== null && $template->signingBlocker() === null,
        ])->save();

        foreach ($old as $path) {
            Storage::disk('private')->delete($path);
        }

        $this->cancelEdit();

        if ($old === [] && $template->isConfigured()) {
            session()->flash('success', 'Template updated.');

            return null;
        }

        session()->flash('success', 'Uploaded. Now choose where the QR code goes.');

        return $this->redirectRoute('pdts.organization.document-templates.qr', $template);
    }

    public function toggleActive(int $id): void
    {
        $template = PdtsDocumentTemplate::findOrFail($id);

        if (! $template->is_active && ! $template->isConfigured()) {
            session()->flash('error', "Place the QR code on \"{$template->name}\" before activating it.");

            return;
        }

        if (! $template->is_active && ($blocker = $template->signingBlocker())) {
            session()->flash('error', "\"{$template->name}\": {$blocker} Open Review to do it.");

            return;
        }

        $template->update(['is_active' => ! $template->is_active]);
    }

    public function delete(int $id): void
    {
        $template = PdtsDocumentTemplate::withCount('documents')->findOrFail($id);
        $used = $template->documents_count;

        // Documents made from it keep all their details and history; they just
        // no longer offer a file download.
        DB::transaction(function () use ($template) {
            $template->documents()->update(['template_id' => null]);
            $template->delete();
        });

        foreach (array_unique(array_filter([$template->storage_path, $template->original_path])) as $path) {
            Storage::disk('private')->delete($path);
        }

        session()->flash('success', "Template \"{$template->name}\" deleted."
            .($used ? " The {$used} ".str('document')->plural($used).' made from it keep their details but no longer offer a file download.' : ''));
    }

    public function render()
    {
        return view('livewire.pdts.organization.document-templates', [
            // Only the latest version of each template; earlier ones are on its View page.
            'templates' => PdtsDocumentTemplate::query()
                ->whereNull('superseded_at')
                ->with(['documentType', 'uploader'])
                ->withCount('documents')
                ->orderBy('name')
                ->get(),
            'documentTypes' => PdtsDocumentType::query()->orderBy('name')->get(),
            'editing' => $this->editingId ? PdtsDocumentTemplate::withCount('documents')->find($this->editingId) : null,
        ])->layout('layouts.master', ['title' => 'Document Templates']);
    }
}
