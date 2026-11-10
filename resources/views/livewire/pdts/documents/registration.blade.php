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

            <div class="form-row">
                <div class="form-group col-md-4">
                    <label for="document-type">Document type</label>
                    <select id="document-type" class="form-control" wire:model="document_type_id" required>
                        <option value="">Select a type</option>
                        @foreach($documentTypes as $documentType)
                            <option value="{{ $documentType->id }}">{{ $documentType->name }}</option>
                        @endforeach
                    </select>
                    @error('document_type_id') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                </div>
                <div class="form-group col-md-4">
                    <label for="originating-department">Originating department</label>
                    <input id="originating-department" type="text" class="form-control" wire:model="originating_department" maxlength="191" required>
                    @error('originating_department') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                </div>
                <div class="form-group col-md-4">
                    <label for="originating-office">Originating office</label>
                    <input id="originating-office" type="text" class="form-control" wire:model="originating_office" maxlength="191" required>
                    @error('originating_office') <div class="text-danger mt-1">{{ $message }}</div> @enderror
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