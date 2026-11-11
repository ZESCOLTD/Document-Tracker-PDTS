<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start mb-4">
        <div>
            <h1 class="h3 mb-1">Register Document</h1>
            <p class="text-muted mb-0">Enter the details from the physical document and attach its scan.</p>
        </div>
        <a href="{{ route('pdts.documents.index') }}" class="btn btn-outline-secondary">Registered Documents</a>
    </div>

    <form wire:submit.prevent="save">
        <section class="bg-white border rounded p-4 mb-4">
            <h2 class="h5 mb-3">Document details</h2>

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

            @php
                $missing = array_filter([
                    'document types' => $documentTypes->isEmpty() ? ($canManage['types'] ? route('pdts.organization.document-types') : true) : null,
                    'departments' => $departments->isEmpty() ? ($canManage['departments'] ? route('pdts.organization.departments') : true) : null,
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
                <div class="form-group col-md-4">
                    <label for="originating-department">Originating department</label>
                    <select id="originating-department" class="form-control @error('department_id') is-invalid @enderror" wire:model.live="department_id" required @disabled($departments->isEmpty())>
                        <option value="">Select a department</option>
                        @foreach($departments as $department)
                            <option value="{{ $department->id }}">{{ $department->name }}</option>
                        @endforeach
                    </select>
                    @error('department_id') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                </div>
                <div class="form-group col-md-4">
                    <label for="originating-office">Originating office</label>
                    <select id="originating-office" class="form-control @error('office_id') is-invalid @enderror" wire:model="office_id" required @disabled($department_id === '' || $offices->isEmpty())>
                        <option value="">{{ $department_id === '' ? 'Choose a department first' : ($offices->isEmpty() ? 'No active offices' : 'Select an office') }}</option>
                        @foreach($offices as $office)
                            <option value="{{ $office->id }}">{{ $office->name }}</option>
                        @endforeach
                    </select>
                    @error('office_id') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                    @if ($department_id !== '' && $offices->isEmpty())
                        <small class="form-text text-muted">
                            This department has no active offices.
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
        </section>

        <section class="bg-white border rounded p-4 mb-4">
            <h2 class="h5 mb-3">Scanned document</h2>
            <div class="form-group mb-0">
                <label for="document-attachment">Primary scan</label>
                <input id="document-attachment" type="file" class="form-control-file" wire:model="attachment" accept=".pdf,.png,.jpg,.jpeg,.tif,.tiff" required>
                <small class="form-text text-muted">PDF, PNG, JPEG, or TIFF. Maximum 20 MB. The file is stored privately.</small>
                @error('attachment') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                <div wire:loading wire:target="attachment" class="text-muted mt-2">Preparing upload...</div>
            </div>
        </section>

        <div class="d-flex justify-content-end">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Register Document</span>
                <span wire:loading wire:target="save">Registering...</span>
            </button>
        </div>
    </form>
</div>