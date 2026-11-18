@php
    // One colour per status, shared by the donut, its legend and the table badges.
    $statusColours = [
        'registered' => '#12756b',
        'awaiting_receipt' => '#d9813a',
        'received' => '#2f6fdf',
        'completed' => '#4caf50',
    ];

    $badges = [
        'registered' => ['Registered', 'badge-registered'],
        'awaiting_receipt' => ['Pending Ack.', 'badge-pending'],
        'received' => ['With Receiver', 'badge-received'],
        'completed' => ['Completed', 'badge-completed'],
    ];

    $kpis = [
        ['key' => 'open', 'label' => 'Open Documents', 'value' => $board['open'], 'icon' => 'fa-file-alt', 'tone' => 'teal',
         'trend' => $board['openTrend'], 'goodWhen' => 'up', 'basis' => 'registered',
         'url' => $mine['canViewDocuments'] ? route('pdts.documents.index') : null],
        ['key' => 'pending', 'label' => 'Pending Acknowledgements', 'value' => $mine['waitingCount'], 'icon' => 'fa-hourglass-half', 'tone' => 'orange',
         'trend' => $board['pendingTrend'], 'goodWhen' => 'down', 'basis' => 'received',
         'url' => $mine['canAcknowledge'] ? route('movements.pending') : null],
        ['key' => 'sent', 'label' => 'Sent, Not Received', 'value' => $mine['sentCount'], 'icon' => 'fa-paper-plane', 'tone' => 'teal',
         'trend' => $board['sentTrend'], 'goodWhen' => 'down', 'basis' => 'sent',
         'url' => $mine['canForward'] ? route('movements.custody') : null],
        ['key' => 'overdue', 'label' => 'Overdue Handovers', 'value' => $board['overdueCount'], 'icon' => 'fa-exclamation-triangle', 'tone' => 'red',
         'trend' => null, 'goodWhen' => 'down', 'basis' => null,
         'note' => 'Not received within '.$overdueAfterDays.' '.Str::plural('day', $overdueAfterDays), 'url' => null],
        ['key' => 'completed', 'label' => 'Completed', 'value' => $board['completed'], 'icon' => 'fa-check-circle', 'tone' => 'green',
         'trend' => $board['completedTrend'], 'goodWhen' => 'up', 'basis' => 'completed',
         'url' => $mine['canViewDocuments'] ? route('pdts.documents.index') : null],
    ];

    $quickActions = array_values(array_filter([
        $mine['canRegister'] ? ['Register Document', 'fa-file-medical', route('pdts.documents.register'), 'qa-orange'] : null,
        $mine['canRegister'] ? ['Track Document', 'fa-map-marker-alt', route('movements.track'), 'qa-teal'] : null,
        $mine['canViewDocuments'] ? ['All Documents', 'fa-chart-bar', route('pdts.documents.index'), 'qa-soft'] : null,
        ! $mine['canRegister'] && $mine['canForward'] ? ['My Custody', 'fa-folder-open', route('movements.custody'), 'qa-teal'] : null,
        ! $mine['canRegister'] && $mine['canAcknowledge'] ? ['Pending', 'fa-inbox', route('movements.pending'), 'qa-orange'] : null,
    ]));

    $donut = $board['byStatus']
        ->filter(fn ($row) => $row['total'] > 0)
        ->map(fn ($row) => ['name' => $row['status']->label(), 'y' => $row['total'], 'color' => $statusColours[$row['status']->value]])
        ->values();
@endphp

<div class="pdts-dash" style="padding: 0 .75rem 90px;" wire:poll.60s>
    <style>
        .pdts-dash { --ink: #1f2d3d; --muted: #6b7785; --line: #edf0f3; }
        .pdts-dash .dash-card { background: #fff; border-radius: .75rem; box-shadow: 0 1px 2px rgba(16, 24, 40, .06), 0 4px 14px rgba(16, 24, 40, .07); }
        .pdts-dash .tone-teal   { --tone: #12756b; --tone-deep: #0b5c54; --tone-soft: #e3f3f1; }
        .pdts-dash .tone-orange { --tone: #d9813a; --tone-deep: #b8672a; --tone-soft: #fbeee3; }
        .pdts-dash .tone-red    { --tone: #e04848; --tone-deep: #c23434; --tone-soft: #fdecec; }
        .pdts-dash .tone-green  { --tone: #3fa35b; --tone-deep: #2f8a48; --tone-soft: #e6f5ea; }
        .pdts-dash .tone-blue   { --tone: #2f6fdf; --tone-deep: #1f52b0; --tone-soft: #e9f1fe; }

        /* Welcome banner (unchanged) */
        .pdts-dash .hero { background: linear-gradient(120deg, #00452B 0%, #075B35 100%); color: #fff; border-radius: .5rem; padding: 1.25rem 1.5rem; position: relative; overflow: hidden; }
        .pdts-dash .hero::after { content: ""; position: absolute; right: -60px; top: -60px; width: 220px; height: 220px; border-radius: 50%; background: radial-gradient(circle, rgba(243, 156, 18, .35), rgba(243, 156, 18, 0) 70%); }
        .pdts-dash .hero h3 { color: #fff; }
        .pdts-dash .hero .hero-sub { color: rgba(255, 255, 255, .78); }
        .pdts-dash .hero .hero-date { color: rgba(255, 255, 255, .65); position: relative; z-index: 1; }
        .pdts-dash .hero .hero-cta { position: relative; z-index: 1; background: #f39c12; border-color: #f39c12; color: #fff; font-weight: 600; }
        .pdts-dash .hero .hero-cta:hover { background: #e08e0b; border-color: #e08e0b; }

        /* KPI cards */
        .pdts-dash .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1rem; margin-bottom: 1rem; }
        .pdts-dash .kpi { position: relative; display: flex; gap: .875rem; align-items: flex-start; padding: 1rem 1.125rem; transition: box-shadow .15s, transform .15s; }
        .pdts-dash .kpi:hover { box-shadow: 0 8px 22px rgba(16, 24, 40, .13); transform: translateY(-1px); }
        .pdts-dash .kpi-icon { width: 3rem; height: 3rem; border-radius: .625rem; display: grid; place-items: center; flex-shrink: 0; color: #fff; font-size: 1.25rem;
            background: linear-gradient(135deg, var(--tone) 0%, var(--tone-deep) 100%); box-shadow: 0 3px 8px rgba(16, 24, 40, .18); }
        .pdts-dash .kpi-label { font-weight: 600; color: var(--ink); line-height: 1.2; font-size: .9375rem; }
        .pdts-dash .kpi-value { font-size: 1.875rem; font-weight: 700; line-height: 1.15; color: var(--ink); font-variant-numeric: tabular-nums; }
        .pdts-dash .tone-red .kpi-value { color: var(--tone); }
        .pdts-dash .kpi-trend { font-size: .8125rem; color: var(--muted); white-space: nowrap; }
        .pdts-dash .kpi-trend .good { color: #2f8a48; font-weight: 600; }
        .pdts-dash .kpi-trend .bad { color: #c23434; font-weight: 600; }
        @media (prefers-reduced-motion: reduce) { .pdts-dash .kpi { transition: none; } .pdts-dash .kpi:hover { transform: none; } }

        /* Panels */
        .pdts-dash .panel { padding: 1.125rem 1.25rem; height: 100%; }
        .pdts-dash .panel-title { font-size: 1.125rem; font-weight: 600; color: var(--ink); margin: 0; display: flex; align-items: center; gap: .5rem; }
        .pdts-dash .panel-title .fas { color: #12756b; }
        .pdts-dash .panel-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: .75rem; gap: 1rem; }
        .pdts-dash .scope-note { font-size: .8125rem; color: var(--muted); }

        /* Donut legend */
        .pdts-dash .donut-wrap { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; }
        .pdts-dash .donut-chart { position: relative; width: 190px; height: 190px; flex-shrink: 0; margin: 0 auto; }
        .pdts-dash .donut-centre { position: absolute; inset: 0; display: grid; place-content: center; text-align: center; pointer-events: none; }
        .pdts-dash .donut-centre strong { font-size: 1.75rem; line-height: 1; color: var(--ink); font-variant-numeric: tabular-nums; }
        .pdts-dash .donut-centre span { font-size: .8125rem; color: var(--muted); }
        .pdts-dash .legend { list-style: none; padding: 0; margin: 0; flex: 1; min-width: 180px; font-size: .875rem; }
        .pdts-dash .legend li { display: flex; align-items: center; gap: .5rem; padding: .3rem 0; }
        .pdts-dash .legend .swatch { width: .75rem; height: .75rem; border-radius: 3px; flex-shrink: 0; }
        .pdts-dash .legend .count { margin-left: auto; font-variant-numeric: tabular-nums; color: var(--ink); white-space: nowrap; }

        /* Workflow steps */
        .pdts-dash .steps { list-style: none; padding: 0; margin: 0; }
        .pdts-dash .steps li { display: flex; align-items: center; gap: .75rem; padding: .55rem .875rem; border-radius: .5rem; background: var(--tone-soft); position: relative; }
        .pdts-dash .steps li + li { margin-top: 1.125rem; }
        .pdts-dash .steps li + li::before { content: "\f063"; font-family: "Font Awesome 5 Free"; font-weight: 900; position: absolute; left: 1.45rem; top: -1.05rem; font-size: .6rem; color: #9aa5b1; }
        .pdts-dash .step-no { width: 1.75rem; height: 1.75rem; border-radius: 50%; background: var(--tone); color: #fff; display: grid; place-items: center; font-weight: 700; font-size: .875rem; flex-shrink: 0; }
        .pdts-dash .step-total { margin-left: auto; font-weight: 700; color: var(--ink); font-variant-numeric: tabular-nums; }

        /* Recent documents table */
        .pdts-dash .recent-table { width: 100%; font-size: .875rem; }
        .pdts-dash .recent-table th { font-weight: 600; color: var(--ink); background: #f5f7f9; padding: .625rem .75rem; white-space: nowrap; border: 0; }
        .pdts-dash .recent-table td { padding: .625rem .75rem; border-top: 1px solid var(--line); vertical-align: middle; }
        .pdts-dash .recent-table .ref { font-weight: 600; font-variant-numeric: tabular-nums; white-space: nowrap; color: var(--ink); }
        .pdts-dash .recent-table .ref .fa-file-alt { color: #6b7785; margin-right: .5rem; }
        .pdts-dash .recent-table .when { white-space: nowrap; color: var(--muted); font-variant-numeric: tabular-nums; }
        .pdts-dash .status-badge { display: inline-block; padding: .2rem .65rem; border-radius: .375rem; font-size: .8125rem; font-weight: 500; white-space: nowrap; }
        .pdts-dash .badge-draft     { background: #eef1f4; color: #5b6673; }
        .pdts-dash .badge-registered{ background: #e3f3f1; color: #0b5c54; }
        .pdts-dash .badge-pending   { background: #fdf0d9; color: #a35d12; }
        .pdts-dash .badge-received  { background: #e1edfd; color: #1f52b0; }
        .pdts-dash .badge-completed { background: #e3f4e6; color: #2a7a3f; }
        .pdts-dash .badge-overdue   { background: #fde3e3; color: #b02a2a; }
        .pdts-dash .view-all { color: #d9813a; font-weight: 600; white-space: nowrap; }
        .pdts-dash .view-all:hover { color: #b8672a; }

        /* Quick actions */
        .pdts-dash .qa-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
        .pdts-dash .qa { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .5rem; min-height: 96px; padding: .75rem; border-radius: .625rem;
            text-align: center; font-weight: 500; font-size: .9375rem; transition: transform .15s, box-shadow .15s; text-decoration: none; }
        .pdts-dash .qa .fas { font-size: 1.75rem; }
        .pdts-dash .qa:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(16, 24, 40, .15); text-decoration: none; }
        .pdts-dash .qa-orange { background: linear-gradient(135deg, #d9813a, #c46e2a); color: #fff; }
        .pdts-dash .qa-teal   { background: linear-gradient(135deg, #12756b, #0b5c54); color: #fff; }
        .pdts-dash .qa-soft   { background: #e9eff1; color: var(--ink); }
        .pdts-dash .qa-orange:hover, .pdts-dash .qa-teal:hover { color: #fff; }
        .pdts-dash .qa-soft:hover { color: var(--ink); }
        @media (prefers-reduced-motion: reduce) { .pdts-dash .qa { transition: none; } .pdts-dash .qa:hover { transform: none; } }

        /* My work lists */
        .pdts-dash .rows { list-style: none; margin: 0; padding: 0; }
        .pdts-dash .rows li { display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: .75rem 0; border-top: 1px solid var(--line); }
        .pdts-dash .rows .ref { font-weight: 600; font-variant-numeric: tabular-nums; }
        .pdts-dash .rows .meta { font-size: .8125rem; color: var(--muted); }
        .pdts-dash .rows .when { font-size: .8125rem; color: var(--muted); white-space: nowrap; text-align: right; }
        .pdts-dash .dot { width: .5rem; height: .5rem; border-radius: 50%; background: var(--tone); flex-shrink: 0; margin-top: .45rem; }
        .pdts-dash .pill { font-size: .7rem; font-weight: 600; padding: .125rem .5rem; border-radius: 999px; background: #fdecea; color: #c0392b; white-space: nowrap; }
        .pdts-dash .empty { padding: 1.25rem 0; color: var(--muted); border-top: 1px solid var(--line); }
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

    <div class="d-flex justify-content-end mb-2">
        <span class="scope-note" data-scope="{{ $board['organisationWide'] ? 'organisation' : 'mine' }}">
            <i class="fas {{ $board['organisationWide'] ? 'fa-globe-africa' : 'fa-user' }} mr-1"></i>
            {{ $board['organisationWide'] ? 'Across the organisation' : 'Documents you registered, hold, sent or received' }}
        </span>
    </div>

    {{-- KPI cards --}}
    <div class="kpi-grid">
        @foreach ($kpis as $kpi)
            <div class="dash-card kpi tone-{{ $kpi['tone'] }}" data-tile="{{ $kpi['key'] }}">
                <span class="kpi-icon"><i class="fas {{ $kpi['icon'] }}" aria-hidden="true"></i></span>
                <div style="min-width: 0;">
                    <div class="kpi-label">{{ $kpi['label'] }}</div>
                    <div class="kpi-value">{{ number_format($kpi['value']) }}</div>
                    <div class="kpi-trend">
                        @if ($kpi['trend'] !== null)
                            @php
                                $up = $kpi['trend'] >= 0;
                                $good = $kpi['trend'] === 0 || ($up === ($kpi['goodWhen'] === 'up'));
                            @endphp
                            <span class="{{ $good ? 'good' : 'bad' }}">
                                <i class="fas {{ $up ? 'fa-arrow-up' : 'fa-arrow-down' }}" aria-hidden="true"></i>
                                {{ $up ? '+' : '' }}{{ $kpi['trend'] }}%
                            </span>
                            <span title="Documents {{ $kpi['basis'] }} in the last 30 days compared with the 30 days before">vs previous 30 days</span>
                        @elseif ($kpi['note'] ?? null)
                            {{ $kpi['note'] }}
                        @else
                            No earlier data to compare
                        @endif
                    </div>
                </div>
                @if ($kpi['url'])
                    <a href="{{ $kpi['url'] }}" class="stretched-link" aria-label="{{ $kpi['label'] }}"></a>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Charts --}}
    <div class="row">
        <div class="col-xl-5 col-lg-6 mb-3">
            <div class="dash-card panel">
                <div class="panel-head">
                    <h4 class="panel-title"><i class="fas fa-chart-line"></i>Document Movement Trend</h4>
                    <span class="scope-note">Last 7 days</span>
                </div>
                <div wire:ignore>
                    <div id="pdts-trend-chart" style="height: 230px;" role="img" aria-label="Documents registered and completed over the last 7 days"></div>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-lg-6 mb-3">
            <div class="dash-card panel">
                <div class="panel-head">
                    <h4 class="panel-title"><i class="fas fa-list-alt"></i>Documents by Status</h4>
                </div>
                <div class="donut-wrap">
                    <div class="donut-chart">
                        <div wire:ignore style="width: 100%; height: 100%;">
                            <div id="pdts-status-chart" style="width: 100%; height: 100%;" role="img" aria-label="Documents by status"></div>
                        </div>
                        <div class="donut-centre">
                            <strong>{{ number_format($board['total']) }}</strong>
                            <span>{{ Str::plural('Document', $board['total']) }}</span>
                        </div>
                    </div>
                    <ul class="legend">
                        @foreach ($board['byStatus'] as $row)
                            <li data-status="{{ $row['status']->value }}">
                                <span class="swatch" style="background: {{ $statusColours[$row['status']->value] }};"></span>
                                {{ $row['status']->label() }}
                                <span class="count">
                                    {{ $row['total'] }}
                                    <span class="text-muted">({{ $board['total'] > 0 ? round($row['total'] / $board['total'] * 100) : 0 }}%)</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-xl-3 mb-3">
            <div class="dash-card panel">
                <div class="panel-head">
                    <h4 class="panel-title"><i class="fas fa-project-diagram"></i>Document Workflow</h4>
                </div>
                <ol class="steps">
                    @foreach ($board['workflow'] as $i => $step)
                        <li class="tone-{{ ['teal', 'orange', 'blue', 'green'][$i] }}">
                            <span class="step-no">{{ $i + 1 }}</span>
                            <span>{{ $step['label'] }}</span>
                            <span class="step-total">{{ number_format($step['total']) }}</span>
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>
    </div>

    {{-- Recent documents and quick actions --}}
    <div class="row">
        <div class="{{ $quickActions ? 'col-xl-9' : 'col-12' }} mb-3">
            <div class="dash-card panel">
                <div class="panel-head">
                    <h4 class="panel-title"><i class="fas fa-file-alt"></i>Recent Documents</h4>
                    @if ($mine['canViewDocuments'])
                        <a href="{{ route('pdts.documents.index') }}" class="view-all">View All <i class="fas fa-arrow-right ml-1"></i></a>
                    @elseif ($mine['canRegister'])
                        <a href="{{ route('movements.track') }}" class="view-all">View All <i class="fas fa-arrow-right ml-1"></i></a>
                    @endif
                </div>
                @if ($board['recent']->isEmpty())
                    <div class="empty">No documents yet.</div>
                @else
                    <div class="table-responsive">
                        <table class="recent-table" data-list="recent">
                            <thead>
                                <tr>
                                    <th>Reference No.</th>
                                    <th>Subject</th>
                                    <th>From</th>
                                    <th>Current Holder</th>
                                    <th>Status</th>
                                    <th>Registered On</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($board['recent'] as $document)
                                    @php
                                        $overdue = $document->pendingMovement && $document->pendingMovement->forwarded_at?->lte($cutoff);
                                        [$badgeText, $badgeClass] = $overdue ? ['Overdue', 'badge-overdue'] : ($badges[$document->status] ?? [$document->status, 'badge-draft']);
                                    @endphp
                                    <tr wire:key="recent-{{ $document->id }}">
                                        <td class="ref"><i class="fas fa-file-alt" aria-hidden="true"></i>{{ $document->reference_number }}</td>
                                        <td>{{ Str::limit($document->title, 40) }}</td>
                                        <td>{{ $document->originating_department }}</td>
                                        <td>{{ $document->currentCustodian?->name ?? '—' }}</td>
                                        <td><span class="status-badge {{ $badgeClass }}">{{ $badgeText }}</span></td>
                                        <td class="when">{{ $document->registered_at?->format('d-M-Y H:i') }}</td>
                                        <td class="text-center">
                                            <a href="{{ route('movements.history', $document) }}" class="text-secondary" title="View history" aria-label="View {{ $document->reference_number }}">
                                                <i class="far fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        @if ($quickActions)
            <div class="col-xl-3 mb-3">
                <div class="dash-card panel">
                    <div class="panel-head">
                        <h4 class="panel-title"><i class="fas fa-bolt" style="color: #d9813a;"></i>Quick Actions</h4>
                    </div>
                    <div class="qa-grid">
                        @foreach ($quickActions as [$label, $icon, $url, $class])
                            <a href="{{ $url }}" class="qa {{ $class }}">
                                <i class="fas {{ $icon }}" aria-hidden="true"></i>{{ $label }}
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif
    </div>

    {{-- My work --}}
    <div class="row">
        <div class="col-lg-6 mb-3">
            <div class="dash-card panel tone-orange">
                <div class="panel-head mb-1">
                    <h4 class="panel-title"><i class="fas fa-inbox" style="color: #d9813a;"></i>Waiting for me</h4>
                    @if ($mine['canAcknowledge'] && $mine['waitingCount'] > 0)
                        <a href="{{ route('movements.pending') }}" class="view-all small">Acknowledge</a>
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
            <div class="dash-card panel tone-teal">
                <div class="panel-head mb-1">
                    <h4 class="panel-title"><i class="fas fa-paper-plane"></i>Sent by me, not received</h4>
                    @if ($mine['canForward'] && $mine['sentCount'] > 0)
                        <a href="{{ route('movements.custody') }}" class="view-all small">Follow up</a>
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
</div>

@script
<script>
    const trend = @js($board['trendChart']);
    const statuses = @js($donut);

    if (window.Highcharts) {
        Highcharts.chart('pdts-trend-chart', {
            chart: { type: 'areaspline', backgroundColor: 'transparent', style: { fontFamily: 'inherit' }, spacing: [8, 4, 4, 0] },
            title: { text: null },
            credits: { enabled: false },
            accessibility: { enabled: false },
            legend: { align: 'right', verticalAlign: 'top', floating: true, itemStyle: { fontWeight: '500', color: '#4a5562' } },
            xAxis: { categories: trend.labels, lineColor: '#dfe3e8', tickLength: 0, labels: { style: { color: '#6b7785' } } },
            yAxis: { title: { text: null }, allowDecimals: false, min: 0, gridLineColor: '#eef1f4', labels: { style: { color: '#6b7785' } } },
            tooltip: { shared: true, borderRadius: 8 },
            plotOptions: { areaspline: { lineWidth: 2.5, marker: { radius: 4, lineWidth: 2, lineColor: '#fff' } } },
            series: [
                { name: 'Registered', data: trend.registered, color: '#12756b',
                  fillColor: { linearGradient: { x1: 0, y1: 0, x2: 0, y2: 1 }, stops: [[0, 'rgba(18,117,107,.28)'], [1, 'rgba(18,117,107,0)']] } },
                { name: 'Completed', data: trend.completed, color: '#d9813a',
                  fillColor: { linearGradient: { x1: 0, y1: 0, x2: 0, y2: 1 }, stops: [[0, 'rgba(217,129,58,.28)'], [1, 'rgba(217,129,58,0)']] } },
            ],
        });

        Highcharts.chart('pdts-status-chart', {
            chart: { type: 'pie', backgroundColor: 'transparent', margin: [0, 0, 0, 0], spacing: [0, 0, 0, 0] },
            title: { text: null },
            credits: { enabled: false },
            accessibility: { enabled: false },
            tooltip: { pointFormat: '<b>{point.y}</b> ({point.percentage:.0f}%)' },
            plotOptions: { pie: { innerSize: '64%', borderWidth: 2, borderColor: '#fff', dataLabels: { enabled: false }, size: '100%' } },
            series: [{ name: 'Documents', data: statuses.length ? statuses : [{ name: 'No documents', y: 1, color: '#eef1f4' }] }],
        });
    }
</script>
@endscript
