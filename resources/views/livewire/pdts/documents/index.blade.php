<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Registered Documents</h1>
            <p class="text-muted mb-0">Search by reference, title, subject, department, or office.</p>
        </div>
        @can('documents.register')
            <a href="{{ route('pdts.documents.register') }}" class="btn btn-primary">
                <i class="fas fa-plus mr-1"></i> Register Document
            </a>
        @endcan
    </div>

    <section class="bg-white border rounded p-3">
        <div class="form-group">
            <label class="sr-only" for="document-search">Search registered documents</label>
            <input id="document-search" type="search" class="form-control" wire:model.live.debounce.300ms="search" placeholder="Search documents...">
        </div>

        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Title / Subject</th>
                        <th>Type</th>
                        <th>Origin</th>
                        <th>Current custodian</th>
                        <th>Status</th>
                        <th>Registered</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($documents as $document)
                        <tr>
                            <td><a href="{{ route('pdts.documents.show', $document) }}" class="font-weight-bold">{{ $document->reference_number }}</a></td>
                            <td>
                                <div>{{ $document->title }}</div>
                                <small class="text-muted">{{ $document->subject }}</small>
                            </td>
                            <td>{{ $document->documentType->name }}</td>
                            <td>{{ $document->originating_office }}<br><small class="text-muted">{{ collect([$document->originating_department, $document->originating_directorate])->filter()->implode(' · ') }}</small></td>
                            <td>{{ $document->currentCustodian->name }}</td>
                            <td><span class="badge badge-secondary">{{ str($document->status)->replace('_', ' ')->title() }}</span></td>
                            <td>{{ $document->registered_at->format('d M Y H:i') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No documents match this search.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-3">{{ $documents->links() }}</div>
    </section>
</div>