@php
    $status = $document->statusEnum();
    $statusTone = match ($document->status) {
        'received' => ['#e7f1ff', '#1f5fbf', 'fa-check-circle'],
        'awaiting_receipt' => ['#fff4e0', '#b86e00', 'fa-shipping-fast'],
        default => ['#eef0f3', '#4a5563', 'fa-file-alt'],
    };
    $pending = $document->pendingMovement;

    $activity = [
        'registered' => ['Registered', 'fa-plus', '#2f6fdf'],
        'handover_forwarded' => ['Forwarded', 'fa-share', '#e8890c'],
        'handover_acknowledged' => ['Received', 'fa-check', '#28a745'],
        'handover_declined' => ['Declined', 'fa-times', '#dc3545'],
        'handover_cancelled' => ['Handover cancelled', 'fa-ban', '#6c757d'],
    ];

    $fileIcon = fn ($attachment) => match (true) {
        str_contains((string) $attachment->mime_type, 'pdf') => ['fa-file-pdf', '#d64545'],
        str_starts_with((string) $attachment->mime_type, 'image/') => ['fa-file-image', '#2f6fdf'],
        default => ['fa-file-alt', '#6c757d'],
    };
    $fileSize = fn (int $bytes) => $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : number_format($bytes / 1024, 1).' KB';
@endphp

<div class="doc-show" style="padding: 0 .75rem 90px;">
    <style>
        .doc-show .label { font-size: .75rem; letter-spacing: .04em; text-transform: uppercase; color: #6c757d; margin-bottom: .125rem; }
        .doc-show .value { font-weight: 500; overflow-wrap: anywhere; }
        .doc-show .ref { font-variant-numeric: tabular-nums; letter-spacing: .01em; }
        .doc-show .status-pill { display: inline-flex; align-items: center; gap: .375rem; padding: .25rem .75rem; border-radius: 999px; font-size: .8125rem; font-weight: 600; }
        .doc-show .custody { display: flex; align-items: center; gap: .875rem; padding: .875rem 1.25rem; border-top: 1px solid #eef0f3; border-radius: 0 0 .25rem .25rem; }
        .doc-show .custody-icon { width: 2.25rem; height: 2.25rem; border-radius: 50%; display: grid; place-items: center; flex-shrink: 0; }
        .doc-show .file-tile { width: 3rem; height: 3rem; border-radius: .5rem; display: grid; place-items: center; font-size: 1.5rem; flex-shrink: 0; background: #f4f6f9; }
        .doc-show .timeline { list-style: none; margin: 0; padding: 0; }
        .doc-show .timeline li { position: relative; display: flex; gap: 1rem; padding-bottom: 1.25rem; }
        .doc-show .timeline li:not(:last-child)::before { content: ""; position: absolute; left: 1rem; top: 2.25rem; bottom: .25rem; width: 2px; background: #e9ecef; }
        .doc-show .timeline .dot { width: 2rem; height: 2rem; border-radius: 50%; display: grid; place-items: center; color: #fff; font-size: .8125rem; flex-shrink: 0; }
        .doc-show .timeline .when { white-space: nowrap; text-align: right; font-size: .8125rem; color: #6c757d; }
        .doc-show .note { font-size: .875rem; color: #555; background: #f7f8fa; border-radius: .25rem; padding: .375rem .625rem; margin-top: .375rem; }
    </style>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    {{-- Header --}}
    <section class="card elevation-2 border-0 mb-3">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-start" style="gap: 1rem;">
            <div style="min-width: 0;">
                <a href="{{ route('pdts.documents.index') }}" class="text-muted small"><i class="fas fa-arrow-left mr-1"></i>Registered Documents</a>
                <h1 class="h3 font-weight-bold mt-2 mb-1 ref">{{ $document->reference_number }}</h1>
                <p class="mb-2 text-muted" style="font-size: 1.0625rem;">{{ $document->title }}</p>
                <div class="d-flex flex-wrap align-items-center" style="gap: .5rem;">
                    <span class="status-pill" style="background: {{ $statusTone[0] }}; color: {{ $statusTone[1] }};">
                        <i class="fas {{ $statusTone[2] }}"></i>{{ $status->plainLabel() }}
                    </span>
                    @if ($document->documentType)
                        <span class="badge badge-light border px-2 py-1">{{ $document->documentType->name }}</span>
                    @endif
                </div>
            </div>

            <div class="d-flex flex-wrap align-items-start" style="gap: .5rem;">
                @if ($canForward)
                    <livewire:movements.forward-document :document="$document" :key="'forward-'.$document->id" />
                @endif
                @if ($canViewHistory)
                    <a href="{{ route('movements.history', $document) }}" class="btn btn-sm btn-outline-primary"><i class="fas fa-route mr-1"></i>Movement history</a>
                @endif
            </div>
        </div>

        {{-- Who is responsible right now --}}
        @if ($pending)
            <div class="custody" style="background: #fff8ec;">
                <span class="custody-icon" style="background: #ffe7c2; color: #b86e00;"><i class="fas fa-shipping-fast"></i></span>
                <div>
                    <div class="font-weight-bold">On its way to {{ $pending->recipient?->name ?? 'a former user' }}</div>
                    <div class="small text-muted">
                        Sent {{ $pending->forwarded_at?->diffForHumans() }}.
                        {{ $document->currentCustodian?->name ?? 'The sender' }} stays responsible until it is acknowledged.
                    </div>
                </div>
            </div>
        @else
            <div class="custody" style="background: #f4f8ff;">
                <span class="custody-icon" style="background: #dce9ff; color: #1f5fbf;"><i class="fas fa-user-check"></i></span>
                <div>
                    <div class="font-weight-bold">With {{ $document->currentCustodian?->name ?? 'a former user' }}</div>
                    @if ($heldSince)
                        <div class="small text-muted">Since {{ $heldSince->format('d M Y H:i') }} ({{ $heldSince->diffForHumans(null, true) }})</div>
                    @endif
                </div>
            </div>
        @endif
    </section>

    <div class="row">
        {{-- Details --}}
        <div class="col-lg-8 mb-3">
            <section class="card elevation-2 border-0 h-100">
                <div class="card-body">
                    <h2 class="h6 font-weight-bold text-uppercase text-muted mb-3" style="letter-spacing: .04em;">Document details</h2>
                    <div class="row">
                        @foreach ([
                            ['Subject', $document->subject ?: 'Not recorded'],
                            ['Type', $document->documentType?->name ?? 'Not recorded'],
                            ['Originating department', $document->originating_department ?: 'Not recorded'],
                            ['Originating office', $document->originating_office ?: 'Not recorded'],
                            ['Registered by', $document->registrar?->name ?? 'Former user'],
                            ['Registered on', $document->registered_at?->format('d M Y H:i') ?? 'Not recorded'],
                        ] as [$label, $value])
                            <div class="col-sm-6 mb-3">
                                <div class="label">{{ $label }}</div>
                                <div class="value">{{ $value }}</div>
                            </div>
                        @endforeach
                    </div>

                    @if ($document->description)
                        <div class="label">Description</div>
                        <div class="note mt-1 mb-0" style="white-space: pre-line;">{{ $document->description }}</div>
                    @endif
                </div>
            </section>
        </div>

        {{-- Scanned copy --}}
        <div class="col-lg-4 mb-3">
            <section class="card elevation-2 border-0 h-100">
                <div class="card-body">
                    <h2 class="h6 font-weight-bold text-uppercase text-muted mb-3" style="letter-spacing: .04em;">Scanned copy</h2>
                    @forelse ($document->attachments as $attachment)
                        @php
                            [$icon, $colour] = $fileIcon($attachment);
                        @endphp
                        <div class="d-flex align-items-start {{ ! $loop->last ? 'border-bottom pb-3 mb-3' : '' }}" style="gap: .75rem;">
                            <span class="file-tile" style="color: {{ $colour }};"><i class="fas {{ $icon }}"></i></span>
                            <div style="min-width: 0;" class="flex-grow-1">
                                <div class="font-weight-bold text-break">{{ $attachment->original_filename }}</div>
                                <div class="small text-muted">
                                    {{ $fileSize((int) $attachment->file_size) }}
                                    @if ($attachment->uploader) · {{ $attachment->uploader->name }} @endif
                                </div>
                                @if ($attachment->is_primary)
                                    <span class="badge badge-light border mt-1">Primary</span>
                                @endif
                                <div class="d-flex flex-wrap mt-2" style="gap: .375rem;">
                                    @if ($attachment->is_primary && $canViewHistory)
                                        <a href="{{ route('movements.scan', $document) }}" target="_blank" rel="noopener" class="btn btn-sm btn-primary text-white"><i class="fas fa-eye mr-1"></i>View</a>
                                    @endif
                                    <a href="{{ route('pdts.documents.attachments.download', [$document, $attachment]) }}" class="btn btn-sm btn-outline-secondary"><i class="fas fa-download mr-1"></i>Download</a>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-file-excel d-block mb-2" style="font-size: 1.75rem; opacity: .5;"></i>
                            No scanned file is attached.
                        </div>
                    @endforelse
                </div>
            </section>
        </div>
    </div>

    {{-- Activity --}}
    <section class="card elevation-2 border-0">
        <div class="card-body">
            <h2 class="h6 font-weight-bold text-uppercase text-muted mb-3" style="letter-spacing: .04em;">Activity</h2>
            @if ($document->auditLogs->isEmpty())
                <p class="text-muted mb-0">No activity recorded yet.</p>
            @else
                <ul class="timeline" data-activity>
                    @foreach ($document->auditLogs->sortByDesc(fn ($log) => [$log->occurred_at?->getTimestamp() ?? 0, $log->id]) as $event)
                        @php
                            [$title, $icon, $colour] = $activity[$event->action] ?? [str($event->action)->replace('_', ' ')->title(), 'fa-circle', '#6c757d'];
                            $to = $people[$event->details['recipient_id'] ?? null] ?? null;
                            $note = $event->details['reason'] ?? $event->details['remarks'] ?? null;
                        @endphp
                        <li>
                            <span class="dot" style="background: {{ $colour }};"><i class="fas {{ $icon }}"></i></span>
                            <div class="flex-grow-1" style="min-width: 0;">
                                <div>
                                    <strong>{{ $title }}</strong>
                                    @if ($event->action === 'handover_forwarded' && $to) to {{ $to }} @endif
                                    <span class="text-muted">by {{ $event->actor?->name ?? 'Former user' }}</span>
                                </div>
                                @if ($note)
                                    <div class="note">{{ in_array($event->action, ['handover_declined', 'handover_cancelled']) ? 'Reason: ' : '' }}{{ $note }}</div>
                                @endif
                            </div>
                            <div class="when">
                                {{ $event->occurred_at?->format('d M Y H:i') }}
                                <div class="small">{{ $event->occurred_at?->diffForHumans() }}</div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </section>
</div>
