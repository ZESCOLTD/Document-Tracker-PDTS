<div class="doc-track" style="padding: 0 .75rem 90px;">
    <style>
        .doc-track { --track-done: {{ config('theme.color_success') }}; --track-pending: #17a2b8; --track-line: #ced4da; }
        .doc-track-stops { display: flex; list-style: none; margin: 0; overflow-x: auto; padding: .5rem 0 0; }
        .doc-track-stop { flex: 1 0 150px; min-width: 150px; padding: 0 .75rem; position: relative; text-align: center; }
        .doc-track-stop::before { background: var(--track-done); content: ''; height: 4px; left: -50%; position: absolute; top: 9px; width: 100%; }
        .doc-track-stop:first-child::before { display: none; }
        .doc-track-stop.is-pending::before { background: repeating-linear-gradient(90deg, var(--track-pending) 0 8px, transparent 8px 14px); }
        .doc-track-marker { background: var(--track-done); border: 3px solid #fff; border-radius: 50%; box-shadow: 0 0 0 2px var(--track-done); display: block; height: 22px; margin: 0 auto .6rem; position: relative; width: 22px; z-index: 1; }
        .doc-track-stop.is-current .doc-track-marker { box-shadow: 0 0 0 2px var(--track-done), 0 0 0 7px rgba(0, 112, 60, .2); }
        .doc-track-stop.is-pending .doc-track-marker { background: linear-gradient(90deg, var(--track-pending) 50%, #fff 50%); box-shadow: 0 0 0 2px var(--track-pending); }
        .doc-track-stop.is-ended .doc-track-marker { background: {{ config('theme.color_sidebar') }}; box-shadow: 0 0 0 2px {{ config('theme.color_sidebar') }}; }
        .doc-track-office { display: block; font-weight: 700; overflow-wrap: anywhere; }
        .doc-track-events { list-style: none; margin: 0; padding: 0; }
        .doc-track-event { border-left: 3px solid var(--track-line); margin-left: 8px; padding: 0 0 1.25rem 1.25rem; position: relative; }
        .doc-track-event:last-child { border-left-color: transparent; padding-bottom: 0; }
        .doc-track-event-dot { background: var(--track-done); border-radius: 50%; height: 16px; left: -9.5px; position: absolute; top: 3px; width: 16px; }
        .doc-track-event.type-pending .doc-track-event-dot { background: var(--track-pending); }
        .doc-track-event.type-declined .doc-track-event-dot { background: #dc3545; }
        .doc-track-event.type-cancelled .doc-track-event-dot { background: #6c757d; }

        @media (max-width: 575.98px) {
            .doc-track-stops { display: block; overflow: visible; }
            .doc-track-stop { min-height: 76px; padding: 0 0 .75rem 2.25rem; text-align: left; }
            .doc-track-stop::before { height: 100%; left: 9px; top: -50%; width: 4px; }
            .doc-track-stop.is-pending::before { background: repeating-linear-gradient(180deg, var(--track-pending) 0 8px, transparent 8px 14px); }
            .doc-track-marker { left: 0; margin: 0; position: absolute; top: 0; }
        }
    </style>

    <div class="d-flex justify-content-between align-items-start flex-wrap mb-3">
        <div>
            <h3 class="font-weight-bold mb-1">{{ $document->reference_number }}</h3>
            <p class="text-muted mb-0">{{ $document->title }}</p>
        </div>
        <span class="badge badge-secondary mt-1 p-2">{{ $document->statusEnum()->plainLabel() }}</span>
    </div>

    <div class="card elevation-2 border-0 mb-4">
        <div class="card-body">
            <h4 class="font-weight-bold mb-1">{{ $track['headline'] }}</h4>
            @if ($track['detail'])
                <p class="text-muted mb-0">{{ $track['detail'] }}</p>
            @endif

            <ol class="doc-track-stops mt-4" aria-label="Offices this document has reached">
                @foreach ($track['stops'] as $stop)
                    <li class="doc-track-stop is-{{ $stop['state'] }}" wire:key="stop-{{ $loop->index }}">
                        <span class="doc-track-marker" aria-hidden="true"></span>
                        <span class="doc-track-office">{{ $stop['office'] }}</span>
                        <span class="d-block small">{{ $stop['label'] }}@if ($stop['state'] === 'current') (current)@endif</span>
                        @if ($stop['person'])
                            <span class="d-block small text-muted">{{ $stop['person'] }}</span>
                        @endif
                        @if ($stop['at'])
                            <span class="d-block small text-muted">{{ $stop['at']->format('d M Y H:i') }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </div>
    </div>

    <div class="card elevation-2 border-0">
        <div class="card-header bg-white border-0 pb-0">
            <h5 class="font-weight-bold mb-0">Movement history</h5>
        </div>
        <div class="card-body">
            <ol class="doc-track-events">
                @foreach ($track['events'] as $event)
                    <li class="doc-track-event type-{{ $event['type'] }}" wire:key="event-{{ $event['order'] }}">
                        <span class="doc-track-event-dot" aria-hidden="true"></span>
                        <div class="d-flex justify-content-between flex-wrap">
                            <strong>{{ $event['title'] }}: {{ $event['office'] }}</strong>
                            <span class="small text-muted text-nowrap">{{ $event['at']?->format('d M Y H:i') }}</span>
                        </div>
                        @if ($event['person'])
                            <div class="small">{{ $event['person'] }}</div>
                        @endif
                        @foreach ($event['details'] as $detail)
                            <div class="small text-muted">{{ $detail }}</div>
                        @endforeach
                        @if ($event['remarks'])
                            <div class="small mt-1">Remarks: {{ $event['remarks'] }}</div>
                        @endif
                    </li>
                @endforeach
            </ol>
        </div>
    </div>
</div>
