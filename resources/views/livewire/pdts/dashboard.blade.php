@php
    $statusColours = [
        'registered' => '#9aa5b1',
        'awaiting_receipt' => '#e8890c',
        'received' => '#2f6fdf',
        'completed' => '#0f9d8a',
        'cancelled' => '#d64545',
    ];

    $tiles = [
        ['key' => 'waiting', 'label' => 'Waiting for me', 'value' => $mine['waitingCount'], 'overdue' => $mine['waitingOverdue'],
         'icon' => 'fa-inbox', 'tone' => 'orange', 'url' => $mine['canAcknowledge'] ? route('movements.pending') : null],
        ['key' => 'custody', 'label' => 'In my custody', 'value' => $mine['custodyCount'], 'overdue' => 0,
         'icon' => 'fa-folder-open', 'tone' => 'blue', 'url' => $mine['canForward'] ? route('movements.custody') : null],
        ['key' => 'sent', 'label' => 'Sent, not received', 'value' => $mine['sentCount'], 'overdue' => $mine['sentOverdue'],
         'icon' => 'fa-paper-plane', 'tone' => 'teal', 'url' => $mine['canForward'] ? route('movements.custody') : null],
        ['key' => 'registered', 'label' => 'Registered by me, open', 'value' => $mine['registeredOpen'], 'overdue' => 0,
         'icon' => 'fa-file-signature', 'tone' => 'indigo', 'url' => $mine['canRegister'] ? route('movements.track') : null],
    ];
@endphp

<div class="pdts-dash" style="padding: 0 .75rem 90px;" wire:poll.60s>
    <style>
        .pdts-dash .dash-card { background: #fff; border-radius: .25rem; box-shadow: 0 3px 6px rgba(0, 0, 0, .16), 0 3px 6px rgba(0, 0, 0, .23); }
        .pdts-dash .dash-label { font-size: .75rem; letter-spacing: .04em; text-transform: uppercase; color: #6c757d; }
        /* Tones: brand orange plus three calm partners. */
        .pdts-dash .tone-orange { --tone: #e8890c; --tone-soft: #fff3e3; --tone-deep: #c96f00; }
        .pdts-dash .tone-blue   { --tone: #2f6fdf; --tone-soft: #e9f1fe; --tone-deep: #1f52b0; }
        .pdts-dash .tone-teal   { --tone: #0f9d8a; --tone-soft: #e3f6f3; --tone-deep: #097a6b; }
        .pdts-dash .tone-indigo { --tone: #6247c9; --tone-soft: #efebfb; --tone-deep: #4a33a3; }
        .pdts-dash .tone-red    { --tone: #d64545; --tone-soft: #fdecec; --tone-deep: #b03030; }
        .pdts-dash .hero { background: linear-gradient(120deg, #1f2d3d 0%, #2b3f56 100%); color: #fff; border-radius: .5rem; padding: 1.25rem 1.5rem; position: relative; overflow: hidden; }
        .pdts-dash .hero::after { content: ""; position: absolute; right: -60px; top: -60px; width: 220px; height: 220px; border-radius: 50%; background: radial-gradient(circle, rgba(243, 156, 18, .35), rgba(243, 156, 18, 0) 70%); }
        .pdts-dash .hero h3 { color: #fff; }
        .pdts-dash .hero .hero-sub { color: rgba(255, 255, 255, .78); }
        .pdts-dash .hero .hero-date { color: rgba(255, 255, 255, .65); position: relative; z-index: 1; }
        .pdts-dash .hero .hero-cta { position: relative; z-index: 1; background: #f39c12; border-color: #f39c12; color: #fff; font-weight: 600; }
        .pdts-dash .hero .hero-cta:hover { background: #e08e0b; border-color: #e08e0b; }
        .pdts-dash .tile { display: flex; align-items: center; gap: .875rem; padding: 1.125rem 1.25rem; height: 100%; transition: box-shadow .15s, transform .15s;
            background: linear-gradient(135deg, var(--tone) 0%, var(--tone-deep) 100%); color: #fff; position: relative; overflow: hidden; }
        .pdts-dash .tile::after { content: ""; position: absolute; right: -28px; bottom: -28px; width: 110px; height: 110px; border-radius: 50%; background: rgba(255, 255, 255, .1); pointer-events: none; }
        .pdts-dash .tile:hover { box-shadow: 0 8px 20px rgba(16, 24, 40, .25); transform: translateY(-1px); }
        .pdts-dash .tile .dash-label { color: rgba(255, 255, 255, .85); }
        .pdts-dash .tile-icon { width: 2.5rem; height: 2.5rem; border-radius: .5rem; display: grid; place-items: center; background: rgba(255, 255, 255, .2); color: #fff; flex-shrink: 0; }
        .pdts-dash .tile .pill { background: #fff; color: #b03030; }
        @media (prefers-reduced-motion: reduce) { .pdts-dash .tile { transition: none; } .pdts-dash .tile:hover { transform: none; } }
        .pdts-dash .stat-card { transition: box-shadow .15s; }
        .pdts-dash .stat-card:hover { box-shadow: 0 6px 16px rgba(16, 24, 40, .14) !important; }
        .pdts-dash .stat-card h3 { font-variant-numeric: tabular-nums; }
        .pdts-dash .dot { width: .5rem; height: .5rem; border-radius: 50%; background: var(--tone); flex-shrink: 0; margin-top: .45rem; }
        .pdts-dash .section-title .fas { color: var(--tone); margin-right: .375rem; }
        .pdts-dash .tile-value { font-size: 1.75rem; font-weight: 700; line-height: 1.1; font-variant-numeric: tabular-nums; }
        .pdts-dash .pill { font-size: .7rem; font-weight: 600; padding: .125rem .5rem; border-radius: 999px; background: #fdecea; color: #c0392b; white-space: nowrap; }
        .pdts-dash .section-title { font-size: 1rem; font-weight: 600; margin: 0; }
        .pdts-dash .rows { list-style: none; margin: 0; padding: 0; }
        .pdts-dash .rows li { display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: .75rem 1.125rem; border-top: 1px solid #f1f3f5; }
        .pdts-dash .rows .ref { font-weight: 600; font-variant-numeric: tabular-nums; }
        .pdts-dash .rows .meta { font-size: .8125rem; color: #6c757d; }
        .pdts-dash .rows .when { font-size: .8125rem; color: #6c757d; white-space: nowrap; text-align: right; }
        .pdts-dash .empty { padding: 1.5rem 1.125rem; color: #6c757d; border-top: 1px solid #f1f3f5; }
        .pdts-dash .status-bar { display: flex; height: 10px; border-radius: 999px; overflow: hidden; background: #f1f3f5; }
        .pdts-dash .swatch { display: inline-block; width: .625rem; height: .625rem; border-radius: 2px; margin-right: .375rem; }
    </style>

    <div class="hero d-flex justify-content-between align-items-center flex-wrap mb-4" style="gap: 1rem;">
        <div style="position: relative; z-index: 1;">
            <div class="hero-date small mb-1">{{ now()->format('l, d F Y') }}</div>
            <h3 class="font-weight-bold mb-1">Welcome, {{ $firstName }}</h3>
            <p class="hero-sub mb-0">
                @if ($mine['waitingCount'] > 0)
                    {{ $mine['waitingCount'] }} {{ Str::plural('document', $mine['waitingCount']) }} {{ $mine['waitingCount'] === 1 ? 'is' : 'are' }} waiting for you to acknowledge.
                @else
                    Nothing is waiting for you to acknowledge.
                @endif
            </p>
        </div>
        @if ($mine['canAcknowledge'] && $mine['waitingCount'] > 0)
            <a href="{{ route('movements.pending') }}" class="btn hero-cta">Review pending</a>
        @elseif ($mine['canRegister'])
            <a href="{{ route('pdts.documents.register') }}" class="btn hero-cta"><i class="fas fa-plus mr-1"></i>Register a document</a>
        @endif
    </div>

    {{-- My work --}}
    <div class="row">
        @foreach ($tiles as $tile)
            <div class="col-xl-3 col-sm-6 mb-3 tone-{{ $tile['tone'] }}" data-tile="{{ $tile['key'] }}">
                <div class="dash-card tile">
                    <span class="tile-icon"><i class="fas {{ $tile['icon'] }}"></i></span>
                    <span class="flex-grow-1">
                        <span class="dash-label d-block">{{ $tile['label'] }}</span>
                        <span class="d-flex align-items-center" style="gap: .5rem;">
                            <span class="tile-value">{{ $tile['value'] }}</span>
                            @if ($tile['overdue'] > 0)
                                <span class="pill">{{ $tile['overdue'] }} overdue</span>
                            @endif
                        </span>
                    </span>
                    @if ($tile['url'])
                        <a href="{{ $tile['url'] }}" class="stretched-link" aria-label="{{ $tile['label'] }}"></a>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    <div class="row">
        <div class="col-lg-6 mb-3">
            <div class="dash-card h-100 tone-orange">
                <div class="d-flex justify-content-between align-items-center px-3 pt-3 pb-2">
                    <h4 class="section-title"><i class="fas fa-inbox"></i>Waiting for me</h4>
                    @if ($mine['canAcknowledge'] && $mine['waitingCount'] > 0)
                        <a href="{{ route('movements.pending') }}" class="small">Acknowledge</a>
                    @endif
                </div>
                @if ($mine['waitingList']->isEmpty())
                    <div class="empty">Nothing is waiting for you.</div>
                @else
                    <ul class="rows" data-list="waiting">
                        @foreach ($mine['waitingList'] as $movement)
                            <li wire:key="waiting-{{ $movement->id }}" class="{{ $movement->forwarded_at->lte($cutoff) ? 'tone-red' : '' }}">
                                <span class="dot align-self-start"></span>
                                <div class="text-truncate mr-auto">
                                    <div class="text-truncate"><span class="ref">{{ $movement->document->reference_number }}</span> · {{ $movement->document->title }}</div>
                                    <div class="meta">From {{ $movement->sender?->name }}</div>
                                </div>
                                <div class="when">
                                    {{ $movement->forwarded_at->diffForHumans() }}
                                    @if ($movement->forwarded_at->lte($cutoff))
                                        <div><span class="pill">Overdue</span></div>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        <div class="col-lg-6 mb-3">
            <div class="dash-card h-100 tone-teal">
                <div class="d-flex justify-content-between align-items-center px-3 pt-3 pb-2">
                    <h4 class="section-title"><i class="fas fa-paper-plane"></i>Sent by me, not received</h4>
                    @if ($mine['canForward'] && $mine['sentCount'] > 0)
                        <a href="{{ route('movements.custody') }}" class="small">Follow up</a>
                    @endif
                </div>
                @if ($mine['sentList']->isEmpty())
                    <div class="empty">Everything you sent has been received.</div>
                @else
                    <ul class="rows" data-list="sent">
                        @foreach ($mine['sentList'] as $movement)
                            <li wire:key="sent-{{ $movement->id }}" class="{{ $movement->forwarded_at->lte($cutoff) ? 'tone-red' : '' }}">
                                <span class="dot align-self-start"></span>
                                <div class="text-truncate mr-auto">
                                    <div class="text-truncate">
                                        <a href="{{ route('movements.history', $movement->document) }}" class="ref">{{ $movement->document->reference_number }}</a>
                                        · {{ $movement->document->title }}
                                    </div>
                                    <div class="meta">To {{ $movement->recipient?->name }}</div>
                                </div>
                                <div class="when">
                                    {{ $movement->forwarded_at->diffForHumans() }}
                                    @if ($movement->forwarded_at->lte($cutoff))
                                        <div><span class="pill">Overdue</span></div>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>

    {{-- Organisation: Management / Auditor and administrators --}}
    @if ($organisation)
        <div class="d-flex justify-content-between align-items-end mt-3 mb-3">
            <h4 class="section-title" style="font-size: 1.125rem;">Across the organisation</h4>
            <span class="small text-muted">Overdue means not acknowledged within {{ $overdueAfterDays }} {{ Str::plural('day', $overdueAfterDays) }}</span>
        </div>

        <div data-section="organisation">
            <div class="row">
                @foreach ([
                    ['Open documents', $organisation['open'], '#1f2d3d', 'fa-folder-open', "of {$organisation['total']} registered"],
                    ['Overdue handovers', $organisation['overdueCount'], '#dc3545', 'fa-hourglass-half', 'waiting for receipt'],
                    ['Registered', $organisation['registered30'], '#28a745', 'fa-file-signature', 'last 30 days'],
                    ['Received', $organisation['received30'], '#17a2b8', 'fa-check-double', "last 30 days · {$organisation['declined30']} declined"],
                ] as [$label, $value, $colour, $icon, $note])
                    <div class="col-xl-3 col-sm-6 mb-3">
                        @include('livewire.pdts.partials.stat-card', compact('label', 'value', 'colour', 'icon', 'note'))
                    </div>
                @endforeach
            </div>

            <div class="card elevation-2 border-0 mb-3">
            <div class="card-body">
                <div class="dash-label mb-2">Documents by status</div>
                <div class="status-bar mb-2" role="img" aria-label="Documents by status">
                    @foreach ($organisation['byStatus'] as $row)
                        @if ($row['total'] > 0)
                            <span style="width: {{ $row['total'] / $organisation['total'] * 100 }}%; background: {{ $statusColours[$row['status']->value] }};"></span>
                        @endif
                    @endforeach
                </div>
                <div class="d-flex flex-wrap small" style="gap: .25rem 1.25rem;">
                    @foreach ($organisation['byStatus'] as $row)
                        <span data-status="{{ $row['status']->value }}">
                            <span class="swatch" style="background: {{ $statusColours[$row['status']->value] }};"></span>{{ $row['status']->label() }}
                            <strong class="ml-1">{{ $row['total'] }}</strong>
                        </span>
                    @endforeach
                </div>
            </div>
            </div>
        </div>

        <div class="dash-card tone-red">
            <div class="px-3 pt-3 pb-2">
                <h4 class="section-title"><i class="fas fa-exclamation-circle"></i>Overdue handovers</h4>
            </div>
            @if ($organisation['overdueList']->isEmpty())
                <div class="empty">No handover is overdue.</div>
            @else
                <ul class="rows" data-list="overdue">
                    @foreach ($organisation['overdueList'] as $movement)
                        <li wire:key="overdue-{{ $movement->id }}" class="tone-red">
                            <span class="dot align-self-start"></span>
                            <div class="text-truncate mr-auto">
                                <div class="text-truncate">
                                    <a href="{{ route('movements.history', $movement->document) }}" class="ref">{{ $movement->document->reference_number }}</a>
                                    · {{ $movement->document->title }}
                                </div>
                                <div class="meta">{{ $movement->sender?->name }} → {{ $movement->recipient?->name }}</div>
                            </div>
                            <div class="when text-danger">{{ $movement->forwarded_at->diffForHumans(null, true) }}</div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif
</div>
