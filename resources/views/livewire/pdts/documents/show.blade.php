<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start mb-4">
        <div>
            <a href="{{ route('pdts.documents.index') }}" class="text-muted">Registered Documents</a>
            <h1 class="h3 mt-2 mb-1">{{ $document->reference_number }}</h1>
            <span class="badge badge-secondary">{{ str($document->status)->replace('_', ' ')->title() }}</span>
        </div>
        <a href="{{ route('pdts.documents.index') }}" class="btn btn-outline-secondary">Back to documents</a>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="row">
        <div class="col-lg-8 mb-3">
            <section class="bg-white border rounded p-4 h-100">
                <h2 class="h5 mb-3">Document details</h2>
                <dl class="row mb-0">
                    <dt class="col-sm-4">Title</dt><dd class="col-sm-8">{{ $document->title }}</dd>
                    <dt class="col-sm-4">Subject</dt><dd class="col-sm-8">{{ $document->subject }}</dd>
                    <dt class="col-sm-4">Type</dt><dd class="col-sm-8">{{ $document->documentType->name }}</dd>
                    <dt class="col-sm-4">Originating department</dt><dd class="col-sm-8">{{ $document->originating_department }}</dd>
                    <dt class="col-sm-4">Originating office</dt><dd class="col-sm-8">{{ $document->originating_office }}</dd>
                    <dt class="col-sm-4">Registered by</dt><dd class="col-sm-8">{{ $document->registrar->name }}</dd>
                    <dt class="col-sm-4">Current custodian</dt><dd class="col-sm-8">{{ $document->currentCustodian->name }}</dd>
                    <dt class="col-sm-4">Registered</dt><dd class="col-sm-8">{{ $document->registered_at->format('d M Y H:i') }}</dd>
                    @if($document->description)
                        <dt class="col-sm-4">Description</dt><dd class="col-sm-8">{{ $document->description }}</dd>
                    @endif
                </dl>
            </section>
        </div>

        <div class="col-lg-4 mb-3">
            <section class="bg-white border rounded p-4 h-100">
                <h2 class="h5 mb-3">Scanned files</h2>
                @forelse($document->attachments as $attachment)
                    <div class="d-flex justify-content-between align-items-start border-bottom py-2">
                        <div class="mr-2">
                            <div class="font-weight-bold">{{ $attachment->original_filename }}</div>
                            <small class="text-muted">{{ number_format($attachment->file_size / 1024, 1) }} KB</small>
                        </div>
                        <a class="btn btn-sm btn-outline-primary" href="{{ route('pdts.documents.attachments.download', [$document, $attachment]) }}">Download</a>
                    </div>
                @empty
                    <p class="text-muted mb-0">No scanned file is attached.</p>
                @endforelse
            </section>
        </div>
    </div>

    <section class="bg-white border rounded p-4">
        <h2 class="h5 mb-3">Registration audit</h2>
        <ol class="mb-0">
            @foreach($document->auditLogs as $event)
                <li class="mb-2">
                    <strong>{{ str($event->action)->replace('_', ' ')->title() }}</strong>
                    <span>by {{ $event->actor->name }}</span>
                    <small class="text-muted">{{ $event->occurred_at->format('d M Y H:i:s') }}</small>
                </li>
            @endforeach
        </ol>
    </section>
</div>