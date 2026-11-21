<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start mb-4" style="gap: .5rem;">
        <div>
            <h1 class="h3 mb-1">New Internal Document</h1>
            <p class="text-muted mb-0">PDTS reserves a tracking reference and gives you the template's own file (Word or PDF) with your details filled in and the QR code already placed.</p>
        </div>
        <a href="{{ route('pdts.documents.register') }}" class="btn btn-outline-secondary">Register a received document instead</a>
    </div>

    <ol class="d-flex flex-wrap list-unstyled small text-muted mb-4" style="gap: 1.25rem;">
        <li><span class="badge badge-primary mr-1">1</span>Fill in the details</li>
        <li><span class="badge badge-secondary mr-1">2</span>Download the file and complete it</li>
        <li><span class="badge badge-secondary mr-1">3</span>Print and sign it</li>
        <li><span class="badge badge-secondary mr-1">4</span>Upload the signed scan to register it</li>
    </ol>

    <form wire:submit.prevent="save">
        <section class="bg-white border rounded p-4 mb-4">
            <h2 class="h5 mb-3">Template</h2>

            @if ($templates->isEmpty())
                <div class="alert alert-warning mb-0">
                    <i class="fas fa-exclamation-triangle mr-1"></i>There are no active templates.
                    @if ($canManageTemplates)
                        <a href="{{ route('pdts.organization.document-templates') }}" class="alert-link">Add one in Configurations.</a>
                    @else
                        Ask an administrator to add one.
                    @endif
                </div>
            @else
                <div class="form-row">
                    <div class="form-group col-md-6 mb-0">
                        <label for="internal-template">Template</label>
                        <select id="internal-template" class="form-control @error('template_id') is-invalid @enderror" wire:model.live="template_id" required>
                            <option value="">Select a template</option>
                            @foreach ($templates as $template)
                                <option value="{{ $template->id }}">{{ $template->name }}{{ $template->documentType ? ' ('.$template->documentType->name.')' : '' }}</option>
                            @endforeach
                        </select>
                        @error('template_id') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                    </div>
                    <div class="form-group col-md-6 mb-0">
                        <label for="document-type">Document type</label>
                        <select id="document-type" class="form-control @error('document_type_id') is-invalid @enderror" wire:model="document_type_id" required @disabled($typeFromTemplate)>
                            @unless ($typeFromTemplate)
                                <option value="">Select a type</option>
                            @endunless
                            @foreach ($documentTypes as $documentType)
                                <option value="{{ $documentType->id }}">{{ $documentType->name }}</option>
                            @endforeach
                        </select>
                        @if ($typeFromTemplate)
                            <small class="form-text text-muted">Set by the template.</small>
                        @endif
                        @error('document_type_id') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                    </div>
                </div>
            @endif
        </section>

        @if (! $selectedTemplate)
            @unless ($templates->isEmpty())
                <div class="alert alert-light border mb-4" data-test="choose-template-first">
                    <i class="fas fa-hand-point-up mr-1 text-muted"></i>Choose a template above. The form then shows the fields that template uses.
                </div>
            @endunless
        @else
        <section class="bg-white border rounded p-4 mb-4" wire:key="fields-{{ $selectedTemplate->id }}" data-test="template-fields">
            <h2 class="h5 mb-1">Details in this template</h2>
            <p class="text-muted small mb-3">
                @if ($formFields)
                    These are the fill-in lines in "{{ $selectedTemplate->name }}", in the order they appear. PDTS writes what you enter onto those lines.
                @else
                    "{{ $selectedTemplate->name }}" has no fill-in lines for PDTS to complete{{ $selectedTemplate->isPdf() ? '; it is printed as it is and filled in by hand' : '' }}.
                @endif
            </p>

            @if ($formFields)
                <div class="form-row">
                    @foreach ($formFields as $field)
                        @php
                            $name = $field['name'];
                        @endphp
                        <div class="form-group col-md-6" wire:key="field-{{ $name }}">
                            @switch($name)
                                @case('to')
                                    <label for="internal-to">To</label>
                                    <input id="internal-to" type="text" class="form-control" wire:model="to" maxlength="255" placeholder="e.g. Director - Corporate Affairs" required>
                                    @error('to') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                                    @break
                                @case('from')
                                    <label for="internal-from">From</label>
                                    <input id="internal-from" type="text" class="form-control" wire:model="from" maxlength="255" placeholder="e.g. Director - Investments & Finance" required>
                                    @error('from') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                                    @break
                                @case('cc')
                                    <label for="internal-cc">CC <span class="text-muted">(optional, one per line)</span></label>
                                    <textarea id="internal-cc" class="form-control" rows="2" wire:model="cc" maxlength="1000" placeholder="Managing Director&#10;All Directors"></textarea>
                                    @error('cc') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                                    @break
                                @case('office_reference')
                                    <label for="internal-office-ref">Office file reference <span class="text-muted">(optional)</span></label>
                                    <input id="internal-office-ref" type="text" class="form-control" wire:model="office_reference" maxlength="100" placeholder="e.g. DIF/DDAS/054/2025">
                                    <small class="form-text text-muted">Your office's own REF. The PDTS tracking reference is printed with the QR code.</small>
                                    @error('office_reference') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                                    @break
                                @case('date')
                                    <label for="internal-date">Date</label>
                                    <input id="internal-date" type="date" class="form-control" wire:model="memo_date" required>
                                    @error('memo_date') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                                    @break
                                @default
                                    <label for="custom-{{ $name }}">{{ $field['label'] }} <span class="text-muted">(optional)</span></label>
                                    <input id="custom-{{ $name }}" type="text" class="form-control" wire:model="custom.{{ $name }}" maxlength="2000">
                                    @error('custom.'.$name) <div class="text-danger mt-1">{{ $message }}</div> @enderror
                            @endswitch
                        </div>
                    @endforeach
                </div>
                @if (collect($formFields)->contains('custom', true))
                    <div class="small text-muted mb-3"><i class="fas fa-info-circle mr-1"></i>A field left empty keeps a line on the paper to fill in by hand.</div>
                @endif
            @endif

            <div class="form-row">
                <div class="form-group col-md-8 mb-md-0">
                    <label for="document-subject">Subject @unless ($uses('subject'))<span class="text-muted">(for the tracking record; this template has no subject line)</span>@endunless</label>
                    <input id="document-subject" type="text" class="form-control" wire:model="subject" maxlength="255" placeholder="e.g. Revision of petty cash limit on mechanical workshop requirements" required>
                    @error('subject') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                </div>
                <div class="form-group col-md-4 mb-0">
                    <label for="document-title">Short title <span class="text-muted">(optional)</span></label>
                    <input id="document-title" type="text" class="form-control" wire:model="title" maxlength="255" placeholder="Defaults to the subject">
                    @error('title') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                </div>
            </div>
        </section>
        @endif

        <section class="bg-white border rounded p-4 mb-4">
            <h2 class="h5 mb-3">Originating office</h2>
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label for="originating-directorate">Directorate</label>
                    <select id="originating-directorate" class="form-control @error('directorate_id') is-invalid @enderror" wire:model.live="directorate_id" required @disabled($directorates->isEmpty())>
                        <option value="">Select a directorate</option>
                        @foreach ($directorates as $directorate)
                            <option value="{{ $directorate->id }}">{{ $directorate->name }}</option>
                        @endforeach
                    </select>
                    @error('directorate_id') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                </div>
                <div class="form-group col-md-4">
                    <label for="originating-department">Department <span class="text-muted">(optional)</span></label>
                    <select id="originating-department" class="form-control @error('department_id') is-invalid @enderror" wire:model.live="department_id" @disabled($directorate_id === '')>
                        <option value="">{{ $directorate_id === '' ? 'Choose a directorate first' : 'None — the directorate itself' }}</option>
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}">{{ $department->name }}</option>
                        @endforeach
                    </select>
                    @error('department_id') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                </div>
                <div class="form-group col-md-4">
                    <label for="originating-office">Office</label>
                    <select id="originating-office" class="form-control @error('office_id') is-invalid @enderror" wire:model="office_id" required @disabled($directorate_id === '' || $offices->isEmpty())>
                        <option value="">{{ $directorate_id === '' ? 'Choose a directorate first' : ($offices->isEmpty() ? 'No active offices' : 'Select an office') }}</option>
                        @foreach ($offices as $office)
                            <option value="{{ $office->id }}">{{ $office->name }}</option>
                        @endforeach
                    </select>
                    @error('office_id') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="form-group mb-0">
                <label for="document-description">Note for the tracking record <span class="text-muted">(optional, not printed)</span></label>
                <textarea id="document-description" class="form-control" rows="2" wire:model="description" maxlength="10000"></textarea>
                @error('description') <div class="text-danger mt-1">{{ $message }}</div> @enderror
            </div>
        </section>

        <div class="d-flex justify-content-end">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save" @disabled($templates->isEmpty())>
                <span wire:loading.remove wire:target="save"><i class="fas fa-file-word mr-1"></i>Create draft</span>
                <span wire:loading wire:target="save">Creating...</span>
            </button>
        </div>
    </form>
</div>
