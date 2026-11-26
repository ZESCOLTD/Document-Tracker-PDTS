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

    @include('livewire.movements.partials.filter-cards')

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
                        <th class="text-nowrap">Title / Subject</th>
                        <th>Type</th>
                        <th>Department</th>
                        <th>Current custodian</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($documents as $document)
                        <tr>
                            {{-- The reference on one line: it is what people look for. --}}
                            <td class="text-nowrap"><a href="{{ route('pdts.documents.show', $document) }}" class="font-weight-bold">{{ $document->reference_number }}</a></td>
                            <td style="min-width: 11rem;">
                                <div>{{ $document->title }}</div>
                                {{-- The subject only when it says something the title does not. --}}
                                @if (trim((string) $document->subject) !== '' && mb_strtolower(trim((string) $document->subject)) !== mb_strtolower(trim((string) $document->title)))
                                    <small class="text-muted">{{ $document->subject }}</small>
                                @endif
                            </td>
                            <td>{{ $document->documentType->name }}</td>
                            {{-- The department alone, in full, wrapping if long (the directorate when there is no department). --}}
                            <td style="min-width: 9rem; max-width: 13rem;">{{ $document->originating_department ?: $document->originating_directorate }}</td>
                            <td>{{ $document->currentCustodian->name }}</td>
                            {{-- A discarded draft is not a live draft: it says so, in a lighter grey. --}}
                            @php $discarded = $document->status === 'draft' && $document->signature_status === 'discarded'; @endphp
                            <td><span class="badge text-white" style="background: {{ $discarded ? '#adb5bd' : match ($document->status) { 'received' => '#093f42', 'awaiting_receipt' => '#e8890c', default => '#6c757d' } }};">{{ $discarded ? 'Discarded' : str($document->status)->replace('_', ' ')->title() }}</span></td>
                            {{-- A draft's date is when it was created; it is registered when signed. --}}
                            {{-- The year only when it is not this year; the full date when pointed at. --}}
                            <td title="{{ ($document->status === 'draft' ? 'Created ' : 'Registered ').$document->registered_at->format('d M Y, H:i') }}"><span class="text-nowrap">{{ $document->registered_at->format($document->registered_at->isCurrentYear() ? 'd M' : 'd M Y') }}</span><br><small class="text-muted">{{ $document->registered_at->format('H:i') }}</small></td>
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