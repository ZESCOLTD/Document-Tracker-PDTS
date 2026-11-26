<div class="container-fluid py-4">
    <div class="mb-3">
        <h1 class="h3 mb-1">Archive</h1>
        <p class="text-muted mb-0">Closed documents: completed (with where they are filed and how long they are kept) and cancelled. Nothing here is deleted automatically.</p>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3" style="gap: .5rem;">
        <ul class="nav nav-pills flex-wrap" style="gap: .25rem;">
            @foreach (['completed' => ['Completed', 'fa-check-double'], 'review' => ['Due for review', 'fa-hourglass-end'], 'cancelled' => ['Cancelled', 'fa-ban']] as $key => [$label, $icon])
                <li class="nav-item">
                    <a href="#" wire:click.prevent="$set('view', '{{ $key }}')" class="nav-link {{ $view === $key ? 'active' : '' }}">
                        <i class="fas {{ $icon }} mr-1"></i>{{ $label }} <span class="badge {{ $view === $key ? 'badge-light' : 'badge-secondary' }} ml-1">{{ $counts[$key] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
        <div class="d-flex flex-wrap" style="gap: .5rem;">
            <select class="form-control form-control-sm" style="max-width: 220px;" wire:model.live="typeId" aria-label="Document type">
                <option value="">All document types</option>
                @foreach ($types as $type)
                    <option value="{{ $type->id }}">{{ $type->name }}{{ $type->retention_years ? ' · kept '.$type->retention_years.' yrs' : '' }}</option>
                @endforeach
            </select>
            <input type="search" class="form-control form-control-sm" style="max-width: 260px;" placeholder="Reference, subject or file number" wire:model.live.debounce.300ms="search">
        </div>
    </div>

    @if ($view === 'review')
        <div class="alert alert-light border small"><i class="fas fa-info-circle mr-1"></i>These completed documents have passed the retention period of their document type. Registry reviews them under ZESCO's records policy; PDTS keeps them until then. Retention periods are set per type in Configurations → Document Types.</div>
    @endif

    <section class="bg-white border rounded">
        <div class="table-responsive">
            @php($showFiled = $documents->contains(fn ($d) => filled($d->file_reference)))
            @php($showKeep = $view === 'review' || $documents->contains(fn ($d) => $d->retainUntil() !== null))
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Reference</th><th>Subject</th><th>Type</th>
                        @if ($view === 'cancelled')
                            <th>Cancelled</th><th>Reason</th>
                        @else
                            <th>Completed</th>
                            @if ($showFiled)<th>Filed at</th>@endif
                            @if ($showKeep)<th>Keep until</th>@endif
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($documents as $document)
                        <tr wire:key="archive-{{ $document->id }}">
                            <td class="text-nowrap"><a href="{{ route('pdts.documents.show', $document) }}">{{ $document->reference_number }}</a></td>
                            <td>{{ $document->subject }}</td>
                            <td>{{ $document->documentType?->name }}</td>
                            @if ($view === 'cancelled')
                                <td class="text-nowrap">{{ $document->cancelled_at?->format('j M Y') }}<div class="small text-muted">{{ $document->canceller?->name }}</div></td>
                                <td class="small">{{ $document->cancel_reason }}</td>
                            @else
                                <td class="text-nowrap">{{ $document->completed_at?->format('j M Y') ?? '—' }}</td>
                                @if ($showFiled)<td>{{ $document->file_reference ?: '—' }}</td>@endif
                                @if ($showKeep)
                                    <td class="text-nowrap">
                                        @if ($until = $document->retainUntil())
                                            <span class="{{ $until->isPast() ? 'text-danger font-weight-bold' : '' }}">{{ $until->format('j M Y') }}</span>
                                        @else
                                            <span class="text-muted">Not set</span>
                                        @endif
                                    </td>
                                @endif
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-5">Nothing here.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
    <div class="mt-3">{{ $documents->links() }}</div>
</div>
