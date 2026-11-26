<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start mb-4">
        <div>
            <h1 class="h3 mb-1">Register Document</h1>
            <p class="text-muted mb-0">Enter the details from the physical document and attach its scan.</p>
        </div>
        <a href="{{ route('pdts.documents.index') }}" class="btn btn-outline-secondary">Registered Documents</a>
    </div>

    <form wire:submit.prevent="save"
          x-data="{ uploading: false, uploadFailed: false, analysing: false }"
          x-on:registration-uploaded.window="if (!analysing) { analysing = true; $wire.fillFromDocument().finally(() => analysing = false) }"
          x-on:livewire-upload-start="uploading = true; uploadFailed = false"
          x-on:livewire-upload-finish="uploading = false"
          x-on:livewire-upload-error="uploading = false; uploadFailed = true"
          x-on:livewire-upload-cancel="uploading = false; uploadFailed = true">
        <section class="bg-white border rounded p-4 mb-4">
            <h2 class="h5 mb-3">Scanned document</h2>
            <div class="form-group mb-0">
                <label for="document-attachment">Primary scan</label>
                <input id="document-attachment" type="file" class="form-control-file" wire:model="attachment"
                       wire:loading.attr="disabled" wire:target="analyseDocument,fillFromDocument,save" x-bind:disabled="analysing"
                       accept=".pdf,.png,.jpg,.jpeg,.tif,.tiff" required>
                <small class="form-text text-muted">PDF, PNG, JPEG, or TIFF. Maximum 20 MB. The file is stored privately.</small>
                @error('attachment') <div class="text-danger mt-1" role="alert">{{ $message }}</div> @enderror
                <div x-show="uploading" style="display: none;" class="text-muted mt-2" role="status">Preparing upload...</div>
                <div x-show="uploadFailed" style="display: none;" class="text-danger mt-2" role="alert">Upload did not complete. Please select the scan again.</div>
            </div>

            @can('ai.ocr.use')
                <p class="small text-muted mt-2 mb-0">Uploaded text is processed by AI. Verify extracted details before registration.</p>
                <div class="mt-3">
                    <div wire:loading wire:target="fillFromDocument" class="text-muted" role="status">
                        <i class="fas fa-spinner fa-spin mr-1" aria-hidden="true"></i>Reading the document and filling details...
                    </div>
                    @if ($attachment && ! $autoFillComplete)
                        <button type="button" class="btn btn-outline-primary" wire:click="fillFromDocument"
                                wire:loading.attr="disabled" wire:target="fillFromDocument,analyseDocument,attachment,save"
                                x-bind:disabled="uploading || uploadFailed || analysing">
                            <i class="fas fa-redo mr-1" aria-hidden="true"></i>{{ $ocrError || $autoFillError ? 'Retry extraction' : 'Extract details' }}
                        </button>
                    @endif
                </div>

                <div x-show="!uploading && !uploadFailed">
                    @if ($ocrError)
                        <div class="alert alert-warning mt-3 mb-0" role="alert">{{ $ocrError }}</div>
                    @endif
                    @if ($autoFillError)
                        <div class="alert alert-warning mt-3 mb-0" role="alert">{{ $autoFillError }}</div>
                    @endif
                    @if ($autoFillComplete)
                        <div class="alert alert-info mt-3 mb-0" role="status">Extraction complete. Existing entries were kept; unclear details remain blank. Review the form before registering.</div>
                    @endif

                    @if ($ocrText !== '')
                        <details class="mt-3" wire:loading.remove wire:target="analyseDocument,fillFromDocument">
                            <summary class="mb-2">Extracted text</summary>
                            <p class="small text-success mb-2" role="status">Analysis complete. {{ $ocrPageCount }} {{ $ocrPageCount === 1 ? 'page' : 'pages' }} processed.</p>
                            <label for="document-extracted-text">Extracted text</label>
                            <textarea id="document-extracted-text" class="form-control" rows="10" readonly>{{ $ocrText }}</textarea>
                        </details>
                    @endif
                </div>
            @endcan
        </section>

        <section class="bg-white border rounded p-4 mb-4" aria-labelledby="applicant-heading">
            <h2 id="applicant-heading" class="h5 mb-3">Applicant</h2>
            <div class="small text-muted mb-3">PHRIS-synced employee profile</div>
            <dl class="row mb-0">
                @foreach (['name' => 'Employee name', 'man_no' => 'Man number', 'job_title' => 'Job title', 'directorate' => 'Directorate', 'division' => 'Division', 'functional_section' => 'Section'] as $field => $label)
                    <div class="col-md-4 mb-3" style="overflow-wrap: anywhere;">
                        <dt class="small text-muted font-weight-normal">{{ $label }}</dt>
                        <dd class="mb-0">{{ $applicantDetails[$field] ?? 'Not available' }}</dd>
                    </div>
                @endforeach
            </dl>
            <div class="small text-muted">Originating unit source: {{ $applicantUnitSource ?: 'Manual selection required' }}</div>
        </section>

        <fieldset class="bg-white border rounded p-4 mb-4" aria-labelledby="document-details-heading"
                  wire:loading.attr="disabled" wire:target="fillFromDocument,analyseDocument,save"
                  x-bind:disabled="uploading || analysing">
            <h2 id="document-details-heading" class="h5 mb-3">Document details</h2>

            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="document-title">Title</label>
                    <input id="document-title" type="text" class="form-control" wire:model="title" maxlength="255" required>
                    @error('title') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                </div>
                <div class="form-group col-md-6">
                    <label for="document-subject">Subject</label>
                    <input id="document-subject" type="text" class="form-control" wire:model="subject" maxlength="255" required>
                    @error('subject') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="document-reference">Document reference <span class="text-muted">(optional)</span></label>
                    <input id="document-reference" type="text" class="form-control" wire:model="document_reference" maxlength="255">
                    <small class="form-text text-muted">Reference on the original document. PDTS generates a separate tracking reference.</small>
                    @error('document_reference') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                </div>
                <div class="form-group col-md-6">
                    <label for="document-date">Document date <span class="text-muted">(optional)</span></label>
                    <input id="document-date" type="date" class="form-control" wire:model="document_date">
                    @error('document_date') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                </div>
            </div>

            @php
                $missing = array_filter([
                    'document types' => $documentTypes->isEmpty() ? ($canManage['types'] ? route('pdts.organization.document-types') : true) : null,
                    'directorates' => $directorates->isEmpty() ? ($canManage['directorates'] ? route('pdts.organization.directorates') : true) : null,
                ]);
            @endphp
            @if ($missing)
                <div class="alert alert-warning" data-setup-missing>
                    <i class="fas fa-exclamation-triangle mr-1"></i>
                    Documents can't be registered until there are active
                    @foreach ($missing as $what => $link)
                        @if (is_string($link))
                            <a href="{{ $link }}" class="alert-link">{{ $what }}</a>
                        @else
                            {{ $what }}
                        @endif
                        @unless ($loop->last) and @endunless
                    @endforeach
                    in Configurations.
                    @if (in_array(true, $missing, true)) Ask an administrator to add them. @endif
                </div>
            @endif

            <div class="form-row">
                <div class="form-group col-md-4">
                    <label for="document-type">Document type</label>
                    <select id="document-type" class="form-control @error('document_type_id') is-invalid @enderror" wire:model="document_type_id" required>
                        <option value="">Select a type</option>
                        @foreach($documentTypes as $documentType)
                            <option value="{{ $documentType->id }}">{{ $documentType->name }}</option>
                        @endforeach
                    </select>
                    @error('document_type_id') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="form-row">
                <div class="form-group col-md-4">
                    <label for="originating-directorate">Originating directorate</label>
                    <select id="originating-directorate" class="form-control @error('directorate_id') is-invalid @enderror" wire:model.live="directorate_id" required @disabled($directorates->isEmpty())>
                        <option value="">Select a directorate</option>
                        @foreach($directorates as $directorate)
                            <option value="{{ $directorate->id }}">{{ $directorate->name }}</option>
                        @endforeach
                    </select>
                    @error('directorate_id') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                </div>
                <div class="form-group col-md-4">
                    <label for="originating-department">Originating department <span class="text-muted">(optional)</span></label>
                    <select id="originating-department" class="form-control @error('department_id') is-invalid @enderror" wire:model.live="department_id" @disabled($directorate_id === '')>
                        <option value="">{{ $directorate_id === '' ? 'Choose a directorate first' : 'None — the directorate itself' }}</option>
                        @foreach($departments as $department)
                            <option value="{{ $department->id }}">{{ $department->name }}</option>
                        @endforeach
                    </select>
                    @error('department_id') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                    @if ($directorate_id !== '')
                        <small class="form-text text-muted">Leave empty when the document comes from the Director's office.</small>
                    @endif
                </div>
                <div class="form-group col-md-4">
                    <label for="originating-office">Originating office</label>
                    <select id="originating-office" class="form-control @error('office_id') is-invalid @enderror" wire:model="office_id" required @disabled($directorate_id === '' || $offices->isEmpty())>
                        <option value="">{{ $directorate_id === '' ? 'Choose a directorate first' : ($offices->isEmpty() ? 'No active offices' : 'Select an office') }}</option>
                        @foreach($offices as $office)
                            <option value="{{ $office->id }}">{{ $office->name }}</option>
                        @endforeach
                    </select>
                    @error('office_id') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                    @if ($directorate_id !== '' && $offices->isEmpty())
                        <small class="form-text text-muted">
                            {{ $department_id === '' ? 'This directorate has no offices of its own.' : 'This department has no active offices.' }}
                            @if ($canManage['offices'])
                                <a href="{{ route('pdts.organization.offices') }}">Add one in Configurations.</a>
                            @else
                                Ask an administrator to add one.
                            @endif
                        </small>
                    @endif
                </div>
            </div>

            <div class="form-group mb-0">
                <label for="document-description">Description <span class="text-muted">(optional)</span></label>
                <textarea id="document-description" class="form-control" rows="3" wire:model="description" maxlength="10000"></textarea>
                @error('description') <div class="text-danger mt-1">{{ $message }}</div> @enderror
            </div>
        </fieldset>

        <div class="d-flex justify-content-end">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save,attachment,analyseDocument,fillFromDocument"
                x-bind:disabled="uploading || uploadFailed || analysing">
                <span wire:loading.remove wire:target="save">Register Document</span>
                <span wire:loading wire:target="save">Registering...</span>
            </button>
        </div>
    </form>
</div>