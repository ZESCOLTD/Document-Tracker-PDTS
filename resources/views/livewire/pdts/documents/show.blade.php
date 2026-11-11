<div class="container-fluid py-4">
    @php
        $statusTone = match ($document->status) {
            'received' => 'badge-info',
            'awaiting_receipt' => 'badge-warning',
            default => 'badge-secondary',
        };
    @endphp

    <div class="d-flex flex-wrap justify-content-between align-items-start mb-4" style="gap: .75rem">
        <div>
            <a href="{{ route('pdts.documents.index') }}" class="text-muted small"><i class="fas fa-arrow-left mr-1"></i>Registered Documents</a>
            <h1 class="h3 mt-2 mb-1">{{ $document->reference_number }}</h1>
            <p class="text-muted mb-2">{{ $document->title }}</p>
            <span class="badge {{ $statusTone }} p-2">{{ $document->statusEnum()->plainLabel() }}</span>
        </div>
        <div class="d-flex flex-wrap" style="gap: .5rem">
            @if ($canViewHistory)
                <a href="{{ route('movements.history', $document) }}" class="btn btn-outline-primary"><i class="fas fa-route mr-1"></i>Movement history</a>
                @if ($document->primaryAttachment)
                    <a href="{{ route('movements.scan', $document) }}" target="_blank" rel="noopener" class="btn btn-outline-primary"><i class="fas fa-file-alt mr-1"></i>View scan</a>
                @endif
            @endif
            <a href="{{ route('pdts.documents.index') }}" class="btn btn-outline-secondary">Back to documents</a>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="row">
        <div class="col-lg-8 mb-3">
            <section class="card elevation-2 border-0 h-100">
                <div class="card-body">
                    <h2 class="h5 mb-3">Document details</h2>
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Subject</dt><dd class="col-sm-8">{{ $document->subject }}</dd>
                        <dt class="col-sm-4">Type</dt><dd class="col-sm-8">{{ $document->documentType?->name ?? 'Not recorded' }}</dd>
                        <dt class="col-sm-4">Originating department</dt><dd class="col-sm-8">{{ $document->originating_department ?: 'Not recorded' }}</dd>
                        <dt class="col-sm-4">Originating office</dt><dd class="col-sm-8">{{ $document->originating_office ?: 'Not recorded' }}</dd>
                        <dt class="col-sm-4">Registered by</dt><dd class="col-sm-8">{{ $document->registrar?->name ?? 'Former user' }}</dd>
                        <dt class="col-sm-4">Registered</dt><dd class="col-sm-8">{{ $document->registered_at?->format('d M Y H:i') ?? 'Not recorded' }}</dd>
                        <dt class="col-sm-4">Current custodian</dt><dd class="col-sm-8">{{ $document->currentCustodian?->name ?? 'Former user' }}</dd>
                        @if ($document->description)
                            <dt class="col-sm-4">Description</dt><dd class="col-sm-8">{{ $document->description }}</dd>
                        @endif
                    </dl>
                </div>
            </section>
        </div>

        <div class="col-lg-4 mb-3">
            <section class="card elevation-2 border-0 h-100">
                <div class="card-body">
                    <h2 class="h5 mb-3">Scanned copy</h2>
                    @forelse ($document->attachments as $attachment)
                        @php $size = (int) $attachment->file_size; @endphp
                        <div class="d-flex justify-content-between align-items-start border-bottom py-2">
                            <div class="mr-2" style="min-width: 0">
                                <div class="font-weight-bold text-break">{{ $attachment->original_filename }}</div>
                                <small class="text-muted d-block">
                                    {{ $size >= 1048576 ? number_format($size / 1048576, 1).' MB' : number_format($size / 1024, 1).' KB' }}
                                    @if ($attachment->uploader) &middot; uploaded by {{ $attachment->uploader->name }} @endif
                                </small>
                                @if ($attachment->is_primary)
                                    <span class="badge badge-light border mt-1">Primary</span>
                                @endif
                            </div>
                            <a class="btn btn-sm btn-outline-primary text-nowrap" href="{{ route('pdts.documents.attachments.download', [$document, $attachment]) }}"><i class="fas fa-download mr-1"></i>Download</a>
                        </div>
                    @empty
                        <p class="text-muted mb-0">No scanned file is attached.</p>
                    @endforelse
                </div>
            </section>
        </div>
    </div>

    <section class="card elevation-2 border-0 p-4">
        <h2 class="h5 mb-3">Audit trail</h2>
        @forelse ($document->auditLogs as $event)
            <div class="d-flex justify-content-between flex-wrap border-bottom py-2">
                <span><strong>{{ str($event->action)->replace('_', ' ')->title() }}</strong> by {{ $event->actor?->name ?? 'Former user' }}</span>
                <small class="text-muted">{{ $event->occurred_at?->format('d M Y H:i:s') }}</small>
            </div>
        @empty
            <p class="text-muted mb-0">No activity recorded yet.</p>
        @endforelse
    </section>
</div>