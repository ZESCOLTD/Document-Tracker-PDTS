<div class="document-analysis px-2 pb-4">
    <style>
        .document-analysis { min-width: 0; }
        .document-analysis .analysis-columns { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 1rem; align-items: start; }
        .document-analysis .analysis-column { min-width: 0; background: #fff; padding: 1rem; border: 1px solid #dee2e6; border-radius: 4px; }
        .document-analysis .scan-frame { display: block; width: 100%; height: 70vh; min-height: 420px; border: 1px solid #dee2e6; background: #f5f6f7; }
        .document-analysis .scan-image { display: block; width: 100%; max-height: 75vh; object-fit: contain; background: #f5f6f7; }
        .document-analysis .tracking-value { overflow-wrap: anywhere; }
        .document-analysis .doc-ai .ai-chat-log { max-height: 480px; }
        @media (max-width: 991px) {
            .document-analysis .analysis-columns { grid-template-columns: minmax(0, 1fr); }
            .document-analysis .scan-frame { height: 55vh; min-height: 320px; }
        }
    </style>
    <header class="mb-3">
        <a href="{{ route('pdts.documents.show', $document) }}" class="small text-muted"><i class="fas fa-arrow-left mr-1" aria-hidden="true"></i>Back to document</a>
        <h1 class="h4 mt-2 mb-1">AI Analysis <span class="text-muted">&middot; {{ $document->reference_number }}</span></h1>
        <div class="text-muted text-break">{{ $document->title }}</div>
    </header>

    <section class="bg-white border rounded p-3 mb-3" aria-label="PDTS tracking" wire:poll.30s.visible>
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-2" style="gap: .5rem;">
            <h2 class="h6 font-weight-bold mb-0"><i class="fas fa-route mr-1" aria-hidden="true"></i>PDTS tracking</h2>
            @if ($tracking['available'])
                <span class="small text-muted">Checked {{ \Carbon\Carbon::parse($tracking['as_of'])->format('d M Y H:i:s P') }}</span>
            @endif
        </div>
        @if ($tracking['available'])
            <div class="row">
                <div class="col-sm-4 mb-2"><div class="small text-muted">Status</div><strong>{{ $tracking['status_label'] }}</strong></div>
                <div class="col-sm-4 mb-2"><div class="small text-muted">Current custodian</div><div class="tracking-value">{{ $tracking['current_custodian'] ?? 'Not recorded' }}</div></div>
                <div class="col-sm-4 mb-2"><a href="{{ route('movements.history', $document) }}" class="btn btn-sm btn-outline-secondary"><i class="fas fa-history mr-1" aria-hidden="true"></i>Movement history</a></div>
            </div>
            @php($handover = $tracking['pending_handover'] ?? $tracking['latest_handover'])
            @if ($handover)
                <h3 class="h6 border-top pt-2 mt-1">{{ $tracking['pending_handover'] ? 'Pending handover' : 'Latest handover' }}{{ $handover['is_return'] ? ' (sent back)' : '' }}</h3>
                <dl class="row small mb-0">
                    @foreach ([
                        'From' => $handover['sender'], 'To' => $handover['recipient'],
                        'Handover status' => str_replace('_', ' ', $handover['status']),
                        'Forwarded' => $handover['forwarded_at'] ? \Carbon\Carbon::parse($handover['forwarded_at'])->format('d M Y H:i P') : null,
                        'Messenger' => $handover['messenger'],
                        'Picked up' => $handover['picked_up_at'] ? \Carbon\Carbon::parse($handover['picked_up_at'])->format('d M Y H:i P') : null,
                        'Delivered' => ($handover['delivered_at'] ?? null) ? \Carbon\Carbon::parse($handover['delivered_at'])->format('d M Y H:i P') : null,
                        'Acknowledged' => $handover['acknowledged_at'] ? \Carbon\Carbon::parse($handover['acknowledged_at'])->format('d M Y H:i P') : null,
                        'Closed' => $handover['closed_at'] ? \Carbon\Carbon::parse($handover['closed_at'])->format('d M Y H:i P') : null,
                    ] as $label => $value)
                        <div class="col-sm-6 col-xl-3 mb-2"><dt class="text-muted font-weight-normal">{{ $label }}</dt><dd class="tracking-value mb-0">{{ $value ?? 'Not recorded' }}</dd></div>
                    @endforeach
                </dl>
            @else
                <div class="small text-muted">No handover recorded.</div>
            @endif
        @else
            <div class="small text-muted">Tracking details are not available with your current permissions.</div>
        @endif
    </section>

    <div class="analysis-columns">
        <section class="analysis-column" aria-label="Primary scanned copy">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3" style="gap: .5rem;">
                <h2 class="h6 font-weight-bold mb-0">Scanned copy</h2>
                @if ($scan)
                    <a href="{{ route('pdts.documents.attachments.download', [$document, $scan]) }}" class="btn btn-sm btn-outline-secondary"><i class="fas fa-download mr-1" aria-hidden="true"></i>Download</a>
                @endif
            </div>
            @if ($scan)
                <div class="small text-muted text-break mb-2">{{ $scan->original_filename }}</div>
                @if ($tracking['available'])
                    <div wire:key="analysis-preview-{{ $scan->id }}-{{ $scan->updated_at?->timestamp }}" wire:ignore>
                        @if (str_starts_with((string) $scan->mime_type, 'image/'))
                            <img class="scan-image" src="{{ route('movements.scan', $document) }}" alt="Primary scan of {{ $document->reference_number }}">
                        @else
                            <iframe class="scan-frame" src="{{ route('movements.scan', $document) }}" title="Primary scan of {{ $document->reference_number }}"></iframe>
                        @endif
                    </div>
                    <a href="{{ route('movements.scan', $document) }}" target="_blank" rel="noopener" class="small d-inline-block mt-2"><i class="fas fa-external-link-alt mr-1" aria-hidden="true"></i>Open scan</a>
                @else
                    <div class="small text-muted">Inline preview is not available with your current permissions.</div>
                @endif
            @else
                <div class="text-muted">No scanned copy is attached.</div>
            @endif
        </section>
        <section class="analysis-column" aria-label="Analysis and chat">
            @include('livewire.pdts.documents.document-ai-panel')
        </section>
    </div>
</div>