@php
    $teal = config('theme.color_sidebar', '#093f42');
@endphp
<div class="container-fluid py-4">
    <style>

        .tpl-drop { position: relative; border: 2px dashed #c9d6d6; border-radius: .875rem; background: #f7fafa; padding: 1.75rem 1rem; text-align: center; transition: border-color .15s, background .15s; }
        .tpl-drop.is-over, .tpl-drop:hover { border-color: {{ $teal }}; background: #eef5f5; }
        .tpl-drop input[type=file] { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; }
        .tpl-drop-icon { width: 3.25rem; height: 3.25rem; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; background: #e2eeee; color: {{ $teal }}; font-size: 1.375rem; margin-bottom: .625rem; }
        .tpl-browse { color: {{ $teal }}; font-weight: 600; text-decoration: underline; }
        .tpl-progress { height: .375rem; border-radius: 999px; background: #e2eeee; overflow: hidden; margin: .75rem auto 0; max-width: 16rem; }
        .tpl-progress > span { display: block; height: 100%; background: {{ $teal }}; transition: width .2s; }
        .tpl-file { display: flex; align-items: center; gap: .875rem; border: 1px solid #dfe6e6; border-radius: .875rem; padding: .875rem 1rem; background: #fff; }
        .tpl-file-icon { width: 2.75rem; height: 2.75rem; flex-shrink: 0; border-radius: .625rem; background: #e8f0fb; color: #2b579a; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; }
    </style>

    <div class="mb-4">
        <h1 class="h3 mb-1">Document Templates</h1>
        <p class="text-muted mb-0">Templates for internal documents. Upload ZESCO's own (Word or PDF) as it is, or build one in PDTS like a form.</p>
    </div>

    @if (session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif
    @if (session('error')) <div class="alert alert-warning">{{ session('error') }}</div> @endif

    <section class="bg-white border rounded p-3 mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap mb-3" style="gap: .5rem;">
            <h2 class="h5 mb-0">Templates</h2>
            <div class="d-flex flex-wrap" style="gap: .5rem;">
                <a href="{{ route('pdts.organization.document-templates.guide') }}" class="btn btn-outline-secondary btn-sm"><i class="fas fa-book-open mr-1"></i>How to prepare a template</a>
                <a href="{{ route('pdts.organization.document-templates.build') }}" class="btn btn-outline-primary btn-sm" data-test="build-template"><i class="fas fa-th-list mr-1"></i>Build a template</a>
                <button type="button" class="btn btn-primary btn-sm" wire:click="openCreate"><i class="fas fa-upload mr-1"></i>Upload template</button>
            </div>
        </div>
        <div class="table-responsive actions-overflow">
            <table class="table table-hover mb-0">
                <thead>
                    <tr><th>Name</th><th>Document type</th><th>File</th><th>Documents</th><th>Status</th><th class="text-right">Actions</th></tr>
                </thead>
                <tbody>
                    @forelse ($templates as $template)
                        <tr wire:key="template-{{ $template->id }}">
                            <td>
                                {{ $template->name }}
                                @if ($template->version > 1)<span class="badge badge-light border ml-1">v{{ $template->version }}</span>@endif
                                @if ($template->description)<div class="small text-muted">{{ $template->description }}</div>@endif
                            </td>
                            <td>{{ $template->documentType?->name ?? 'Chosen by the author' }}</td>
                            <td>
                                @if ($template->isBuilt())
                                    <span class="small"><i class="fas fa-th-list mr-1" style="color: {{ $teal }};"></i>Built in PDTS</span>
                                @else
                                    <span class="small"><i class="fas {{ $template->isPdf() ? 'fa-file-pdf text-danger' : 'fa-file-word text-primary' }} mr-1"></i>{{ $template->original_filename }}</span>
                                @endif
                                @if ($template->uploader)
                                    <div class="small text-muted">by {{ $template->uploader->name }}, {{ $template->updated_at->format('d M Y') }}</div>
                                @endif
                            </td>

                            <td>{{ $template->documents_count }}</td>
                            <td>
                                {{-- What still needs doing, one click away. --}}
                                @if (! $template->isConfigured())
                                    <span class="badge badge-warning">Needs QR code</span>
                                    <a href="{{ route('pdts.organization.document-templates.qr', $template) }}" class="small d-block mt-1">Place the QR code</a>
                                @elseif (! $template->is_active && $template->signingBlocker())
                                    <span class="badge badge-warning">Needs a signature spot</span>
                                    <a href="{{ route('pdts.organization.document-templates.review', $template) }}" class="small d-block mt-1">Sort it out</a>
                                @else
                                    <span class="badge {{ $template->is_active ? 'badge-success' : 'badge-secondary' }}">{{ $template->is_active ? 'Active' : 'Inactive' }}</span>
                                @endif
                            </td>
                            <td class="text-right">
                                <x-pdts.row-actions>
                                    {{-- View: a quick look at the template with its QR code. The review page: what PDTS found, the form, Try it. --}}
                                    <a class="dropdown-item" role="menuitem" href="{{ route('pdts.organization.document-templates.view', $template) }}"><i class="fas fa-eye fa-fw mr-2 text-muted" aria-hidden="true"></i>View</a>
                                    @if ($template->isConfigured())
                                        <a class="dropdown-item" role="menuitem" href="{{ route('pdts.organization.document-templates.review', $template) }}"><i class="fas fa-clipboard-check fa-fw mr-2 text-muted" aria-hidden="true"></i>{{ $template->isBuilt() ? 'Check and try it' : 'Form settings and try it' }}</a>
                                    @endif
                                    @if ($template->isBuilt() && ! $template->isSuperseded())
                                        <a class="dropdown-item" role="menuitem" href="{{ route('pdts.organization.document-templates.edit-design', $template) }}"><i class="fas fa-th-list fa-fw mr-2 text-muted" aria-hidden="true"></i>Edit in the builder</a>
                                    @endif
                                    {{-- Uploaded files only: a built template's QR code is placed in the builder. --}}
                                    @if (! $template->documents_count && ! $template->isBuilt())
                                        <a class="dropdown-item" role="menuitem" href="{{ route('pdts.organization.document-templates.qr', $template) }}"><i class="fas fa-qrcode fa-fw mr-2 text-muted" aria-hidden="true"></i>{{ $template->isConfigured() ? 'Move QR code' : 'Place QR code' }}</a>
                                    @endif
                                    {{-- Uploaded files only: a built template's name, type and content are set in the builder. --}}
                                    @unless ($template->isBuilt())
                                        <x-pdts.row-action icon="fa-pen" wire:click="edit({{ $template->id }})">{{ $template->documents_count ? 'Rename or upload a new version' : 'Rename or replace the file' }}</x-pdts.row-action>
                                    @endunless
                                    @if ($template->isConfigured())
                                        <x-pdts.row-action icon="{{ $template->is_active ? 'fa-toggle-off' : 'fa-toggle-on' }}" wire:click="toggleActive({{ $template->id }})">{{ $template->is_active ? 'Deactivate' : 'Activate' }}</x-pdts.row-action>
                                    @endif
                                    @unless ($template->isBuilt())
                                        {{-- The file exactly as uploaded, to edit in Word and upload again. --}}
                                        <a class="dropdown-item" role="menuitem" href="{{ route('pdts.organization.document-templates.original', $template) }}" download><i class="fas fa-file-download fa-fw mr-2 text-muted" aria-hidden="true"></i>{{ $template->isPdf() ? 'Download the PDF' : 'Download the Word file' }}</a>
                                    @endunless
                                    <div class="dropdown-divider"></div>
                                    <x-pdts.row-action icon="fa-trash-alt" danger wire:click="delete({{ $template->id }})"
                                        wire:confirm="{{ $template->documents_count
                                            ? 'Delete '.$template->name.'? '.$template->documents_count.' '.str('document')->plural($template->documents_count).' made from it will keep their details but will no longer offer a file download. This cannot be undone.'
                                            : 'Delete '.$template->name.'? This cannot be undone.' }}">Delete</x-pdts.row-action>
                                </x-pdts.row-actions>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">No templates yet. Upload ZESCO's Word or PDF template to start.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <p class="small text-muted mb-0" data-test="templates-help">
        <i class="fas fa-info-circle mr-1" aria-hidden="true"></i>Upload ZESCO's own Word file as it is, or build a template in PDTS. See
        <a href="{{ route('pdts.organization.document-templates.guide') }}">how to prepare a template</a> for the rules PDTS follows.
    </p>

    @if ($showForm)
        @php
            $locked = $editing && $editing->documents_count > 0;
            $chosenPdf = $file && strtolower($file->getClientOriginalExtension()) === 'pdf';
        @endphp
        <x-pdts.form-modal :title="$editingId ? 'Edit template' : 'Upload a template'" :submit="$editingId ? ($file ? 'Upload and place QR code' : 'Save changes') : 'Upload and place QR code'">
            <div class="form-row">
                <div class="form-group col-md-7">
                    <label for="template-name" class="font-weight-bold">Template name</label>
                    <input id="template-name" class="form-control @error('name') is-invalid @enderror" wire:model="name" maxlength="150" placeholder="e.g. Internal Memorandum" required>
                    @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="form-group col-md-5">
                    <label for="template-type" class="font-weight-bold">Document type <span class="text-muted font-weight-normal">(optional)</span></label>
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
                <label for="template-description" class="font-weight-bold">Description <span class="text-muted font-weight-normal">(optional)</span></label>
                <textarea id="template-description" rows="2" class="form-control @error('description') is-invalid @enderror" wire:model="description" maxlength="1000" placeholder="e.g. Standard internal memo used by all directorates"></textarea>
                @error('description') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="form-group mb-2">
                <label class="font-weight-bold d-block">Template file</label>

                @if ($editing && $editing->original_filename && ! $file)
                    <div class="tpl-file mb-2">
                        <span class="tpl-file-icon"><i class="fas {{ $editing->isPdf() ? 'fa-file-pdf' : 'fa-file-word' }}" aria-hidden="true"></i></span>
                        <div class="flex-grow-1" style="min-width: 0;">
                            <div class="font-weight-bold text-truncate">{{ $editing->original_filename }}</div>
                            <div class="small text-muted">Current file{{ $locked ? ' · used by '.$editing->documents_count.' '.str('document')->plural($editing->documents_count) : '' }}</div>
                        </div>
                        <a href="{{ route('pdts.organization.document-templates.original', $editing) }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary"><i class="fas fa-external-link-alt mr-1"></i>Open</a>
                    </div>
                @endif

                @if ($file)
                    <div class="tpl-file">
                        <span class="tpl-file-icon" @if ($chosenPdf) style="background: #fdecea; color: #c0392b;" @endif><i class="fas {{ $chosenPdf ? 'fa-file-pdf' : 'fa-file-word' }}" aria-hidden="true"></i></span>
                        <div class="flex-grow-1" style="min-width: 0;">
                            <div class="font-weight-bold text-truncate">{{ $file->getClientOriginalName() }}</div>
                            <div class="small text-muted">{{ number_format(max(1, $file->getSize() / 1024)) }} KB · ready to upload. Next you will place the QR code.</div>
                        </div>
                        <button type="button" class="btn btn-sm btn-light" wire:click="removeFile" aria-label="Remove file"><i class="fas fa-times"></i></button>
                    </div>
                @else
                    @if ($locked)
                        <div class="small text-muted mb-2" data-test="new-version-note"><i class="fas fa-code-branch mr-1"></i>Documents were made from this template. A new file becomes <strong>version {{ ($editing?->version ?? 1) + 1 }}</strong>; the documents made so far keep this version.</div>
                    @endif
                    <div class="tpl-drop" x-data="{ over: false, uploading: false, progress: 0 }"
                         :class="{ 'is-over': over }"
                         x-on:dragenter="over = true" x-on:dragleave="over = false" x-on:drop="over = false"
                         x-on:livewire-upload-start="uploading = true; progress = 0"
                         x-on:livewire-upload-progress="progress = $event.detail.progress"
                         x-on:livewire-upload-finish="uploading = false" x-on:livewire-upload-error="uploading = false">
                        <input id="template-file" type="file" wire:model="file" accept=".docx,.pdf,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document" aria-label="Choose a template file">
                        <span class="tpl-drop-icon"><i class="fas fa-cloud-upload-alt" aria-hidden="true"></i></span>
                        <div x-show="! uploading">
                            <div class="font-weight-bold">{{ $editing ? 'Drag a new file here to replace it' : 'Drag the template here' }}</div>
                            <div class="small text-muted">or <span class="tpl-browse">browse your computer</span> · Word (.docx) or PDF, up to 10 MB</div>
                        </div>
                        <div x-show="uploading" x-cloak>
                            <div class="font-weight-bold">Uploading… <span x-text="progress + '%'"></span></div>
                            <div class="tpl-progress"><span :style="'width:' + progress + '%'"></span></div>
                        </div>
                        <div wire:loading wire:target="file" class="small text-muted mt-2"><i class="fas fa-circle-notch fa-spin mr-1"></i>Checking the file…</div>
                    </div>
                @endif

                @error('file') <div class="text-danger small mt-2"><i class="fas fa-exclamation-circle mr-1"></i>{{ $message }}</div> @enderror
            </div>

            @if ($editing && $editing->isConfigured())
                <div class="custom-control custom-switch mt-3">
                    <input id="template-active" type="checkbox" class="custom-control-input" wire:model="isActive">
                    <label class="custom-control-label" for="template-active">Active: authors can choose this template</label>
                </div>
            @endif
        </x-pdts.form-modal>
    @endif
</div>
