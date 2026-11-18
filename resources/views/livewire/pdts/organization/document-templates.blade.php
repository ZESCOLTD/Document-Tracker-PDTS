<div class="container-fluid py-4">
    <div class="mb-4">
        <h1 class="h3 mb-1">Document Templates</h1>
        <p class="text-muted mb-0">Word templates for internal documents. PDTS fills in the details and puts the QR code and tracking reference in the footer.</p>
    </div>

    @if (session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif
    @if (session('error')) <div class="alert alert-warning">{{ session('error') }}</div> @endif

    <section class="bg-white border rounded p-3 mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap mb-3" style="gap: .5rem;">
            <h2 class="h5 mb-0">Templates</h2>
            <button type="button" class="btn btn-primary btn-sm" wire:click="openCreate"><i class="fas fa-upload mr-1"></i>Upload template</button>
        </div>
        <div class="table-responsive actions-overflow">
            <table class="table table-hover mb-0">
                <thead>
                    <tr><th>Name</th><th>Document type</th><th>Source</th><th>Documents</th><th>Status</th><th class="text-right">Actions</th></tr>
                </thead>
                <tbody>
                    @forelse ($templates as $template)
                        <tr wire:key="template-{{ $template->id }}">
                            <td>{{ $template->name }}</td>
                            <td>{{ $template->documentType?->name ?? 'Chosen by the author' }}</td>
                            <td>
                                @if ($template->is_builtin)
                                    <span class="badge badge-info">Built in</span>
                                @else
                                    <span class="small">{{ $template->original_filename }}</span>
                                    @if ($template->uploader)
                                        <div class="small text-muted">by {{ $template->uploader->name }}, {{ $template->updated_at->format('d M Y') }}</div>
                                    @endif
                                @endif
                            </td>
                            <td>{{ $template->documents_count }}</td>
                            <td><span class="badge {{ $template->is_active ? 'badge-success' : 'badge-secondary' }}">{{ $template->is_active ? 'Active' : 'Inactive' }}</span></td>
                            <td class="text-right">
                                <x-pdts.row-actions>
                                    <x-pdts.row-action icon="fa-pen" wire:click="edit({{ $template->id }})">Edit</x-pdts.row-action>
                                    @unless ($template->is_builtin)
                                        <a class="dropdown-item" role="menuitem" href="{{ route('pdts.organization.document-templates.download', $template) }}"><i class="fas fa-download fa-fw mr-2 text-muted" aria-hidden="true"></i>Download</a>
                                    @endunless
                                    <x-pdts.row-action icon="{{ $template->is_active ? 'fa-toggle-off' : 'fa-toggle-on' }}" wire:click="toggleActive({{ $template->id }})">{{ $template->is_active ? 'Deactivate' : 'Activate' }}</x-pdts.row-action>
                                    @if (! $template->is_builtin && $template->documents_count === 0)
                                        <div class="dropdown-divider"></div>
                                        <x-pdts.row-action icon="fa-trash-alt" danger wire:click="delete({{ $template->id }})" wire:confirm="Delete {{ $template->name }}?">Delete</x-pdts.row-action>
                                    @endif
                                </x-pdts.row-actions>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">No templates yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="bg-white border rounded p-3">
        <h2 class="h5 mb-2">Making a template</h2>
        <p class="text-muted mb-3">Start from the office's usual Word file and type these placeholders where the details should go. <code>${qr_code}</code> is required: put it on its own in the footer so every page carries the QR code.</p>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Placeholder</th><th>Becomes</th></tr></thead>
                <tbody>
                    @foreach ($placeholders as $key => $meaning)
                        <tr><td><code>${{ '{' }}{{ $key }}{{ '}' }}</code></td><td>{{ $meaning }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    @if ($showForm)
        <x-pdts.form-modal :title="$editingId ? 'Edit template' : 'Upload template'" :submit="$editingId ? 'Save changes' : 'Upload template'">
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="template-name">Name</label>
                    <input id="template-name" class="form-control @error('name') is-invalid @enderror" wire:model="name" maxlength="150" placeholder="e.g. Internal Minute" required>
                    @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="form-group col-md-6">
                    <label for="template-type">Document type <span class="text-muted">(optional)</span></label>
                    <select id="template-type" class="form-control @error('documentTypeId') is-invalid @enderror" wire:model="documentTypeId">
                        <option value="">Let the author choose</option>
                        @foreach ($documentTypes as $type)
                            <option value="{{ $type->id }}">{{ $type->name }}{{ $type->is_active ? '' : ' (inactive)' }}</option>
                        @endforeach
                    </select>
                    @error('documentTypeId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>
            <div class="form-group">
                <label for="template-file">Word file (.docx) @if ($editingId)<span class="text-muted">(optional, replaces the current file)</span>@endif</label>
                <input id="template-file" type="file" class="form-control-file" wire:model="file" accept=".docx">
                <small class="form-text text-muted">Maximum 10 MB. It must contain <code>${qr_code}</code>.</small>
                @error('file') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                <div wire:loading wire:target="file" class="text-muted mt-1">Uploading...</div>
            </div>
            <div class="custom-control custom-switch">
                <input id="template-active" type="checkbox" class="custom-control-input" wire:model="isActive">
                <label class="custom-control-label" for="template-active">Active</label>
            </div>
        </x-pdts.form-modal>
    @endif
</div>
