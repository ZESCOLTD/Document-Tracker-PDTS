@php
    // One colour and label per status, shared by the donut, its legend and the table badges.
    $statusColours = [
    'draft' => '#c5ccd4',
        'registered' => '#093f42',
        'awaiting_receipt' => '#d9813a',
        'received' => '#2f6fdf',
        'completed' => '#4caf50',
    ];
    $statusLabels = [
        'draft' => 'Draft',
        'registered' => 'Registered',
        'awaiting_receipt' => 'Awaiting Receipt',
        'received' => 'In Progress',
        'completed' => 'Completed',
    ];
    $badges = [
        'draft' => ['Draft', 'badge-registered'],
        'registered' => ['Registered', 'badge-registered'],
        'awaiting_receipt' => ['Pending Ack.', 'badge-pending'],
        'received' => ['With Receiver', 'badge-received'],
        'completed' => ['Completed', 'badge-completed'],
    ];

    // goodWhen decides whether a rise is shown green or red.
    $kpis = [
        ['key' => 'open', 'label' => 'Open Documents', 'value' => $board['open'], 'icon' => 'fa-file-alt', 'tone' => 'teal',
         'trend' => $board['openTrend'], 'goodWhen' => 'up',
         'url' => $mine['canViewDocuments'] ? route('pdts.documents.index') : null],
        ['key' => 'pending', 'label' => 'Pending Acknowledgements', 'value' => $mine['waitingCount'], 'icon' => 'fa-hourglass-half', 'tone' => 'orange',
         'trend' => $board['pendingTrend'], 'goodWhen' => 'down',
         'url' => $mine['canAcknowledge'] ? route('movements.pending') : null],
        ['key' => 'sent', 'label' => 'Sent, Not Received', 'value' => $mine['sentCount'], 'icon' => 'fa-paper-plane', 'tone' => 'teal',
         'trend' => $board['sentTrend'], 'goodWhen' => 'down',
         'url' => $mine['canForward'] ? route('movements.custody') : null],
        ['key' => 'overdue', 'label' => 'Overdue Handovers', 'value' => $board['overdueCount'], 'icon' => 'fa-exclamation-triangle', 'tone' => 'red',
         'trend' => $board['overdueTrend'], 'goodWhen' => 'down', 'url' => null],
        ['key' => 'completed', 'label' => 'Completed', 'value' => $board['completed'], 'icon' => 'fa-check-circle', 'tone' => 'green',
         'trend' => $board['completedTrend'], 'goodWhen' => 'up',
         'url' => $mine['canViewDocuments'] ? route('pdts.documents.index') : null],
    ];

    $quickActions = array_values(array_filter([
        $mine['canRegister'] ? ['Register Document', 'fa-file-medical', route('pdts.documents.register'), 'qa-orange'] : null,
        $mine['canRegister'] ? ['Track Document', 'fa-map-marker-alt', route('movements.track'), 'qa-teal'] : null,
        $mine['canViewDocuments'] ? ['Reports', 'fa-signal', route('pdts.documents.index'), 'qa-soft'] : null,
        ! $mine['canRegister'] && $mine['canAcknowledge'] ? ['Pending Acknowledgements', 'fa-inbox', route('movements.pending'), 'qa-orange'] : null,
        ! $mine['canRegister'] && $mine['canForward'] ? ['My Custody', 'fa-folder-open', route('movements.custody'), 'qa-teal'] : null,
    ]));

    $donut = $board['byStatus']
        ->filter(fn ($row) => $row['total'] > 0)
        ->map(fn ($row) => ['name' => $statusLabels[$row['status']->value], 'y' => $row['total'], 'color' => $statusColours[$row['status']->value]])
        ->values();
@endphp

<div class="pdts-dash" style="padding: 0 .75rem 90px;" wire:poll.60s>
    <style>
        .pdts-dash { --ink: #1f2937; --muted: #4b5563; --line: #edf0f3; }
        /* The layout's DM Sans ships only a Light weight, so bold text is faked.
           Everything below the banner uses Poppins, which the layout loads in real weights. */
        .pdts-dash > :not(.hero) { font-family: 'Poppins', sans-serif; color: var(--ink); }
        .pdts-dash .dash-card { background: #fff; border-radius: .75rem; box-shadow: 0 1px 3px rgba(16, 24, 40, .06), 0 4px 16px rgba(16, 24, 40, .08); }
        .pdts-dash .tone-teal   { --tone: #0f5257; --tone-deep: #093f42; }
        .pdts-dash .tone-orange { --tone: #d9813a; --tone-deep: #b8672a; }
        .pdts-dash .tone-red    { --tone: #ec5353; --tone-deep: #cf3a3a; }
        .pdts-dash .tone-green  { --tone: #47ad62; --tone-deep: #33914d; }
        .pdts-dash .tone-blue   { --tone: #3b82e0; --tone-deep: #2563c4; }

        /* Welcome banner (unchanged) */
        .pdts-dash .hero { background: linear-gradient(120deg, #093f42 0%, #062e30 100%); color: #fff; border-radius: .5rem; padding: 1.25rem 1.5rem; position: relative; overflow: hidden; }
        .pdts-dash .hero::after { content: ""; position: absolute; right: -60px; top: -60px; width: 220px; height: 220px; border-radius: 50%; background: radial-gradient(circle, rgba(202, 117, 51, .35), rgba(202, 117, 51, 0) 70%); }
        .pdts-dash .hero h3 { color: #fff; }
        .pdts-dash .hero .hero-sub { color: rgba(255, 255, 255, .78); }
        .pdts-dash .hero .hero-date { color: rgba(255, 255, 255, .65); position: relative; z-index: 1; }
        .pdts-dash .hero .hero-cta { position: relative; z-index: 1; background: #ca7533; border-color: #ca7533; color: #fff; font-weight: 600; }
        .pdts-dash .hero .hero-cta:hover { background: #b0642a; border-color: #b0642a; }

        /* KPI cards: five across */
        /* All five on one line from tablet width up; they only stack on small screens. */
        .pdts-dash .kpi-grid { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: .875rem; margin-bottom: 1.25rem; }
        @media (max-width: 991.98px)  { .pdts-dash .kpi-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (max-width: 767.98px)  { .pdts-dash .kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 575.98px)  { .pdts-dash .kpi-grid { grid-template-columns: 1fr; } }
        .pdts-dash .kpi { position: relative; padding: .75rem .75rem .625rem; min-width: 0; container-type: inline-size; transition: box-shadow .15s, transform .15s; }
        .pdts-dash .kpi:hover { box-shadow: 0 8px 22px rgba(16, 24, 40, .14); transform: translateY(-1px); }
        .pdts-dash .kpi-top { display: flex; align-items: center; gap: .625rem; }
        .pdts-dash .kpi-icon { width: 2.5rem; height: 2.5rem; border-radius: .5rem; display: grid; place-items: center; flex-shrink: 0; color: #fff; font-size: 1.0625rem;
            background: linear-gradient(135deg, var(--tone) 0%, var(--tone-deep) 100%); box-shadow: 0 3px 8px rgba(16, 24, 40, .18); }
        .pdts-dash .kpi-label { font-weight: 500; line-height: 1.2; font-size: .8125rem; overflow-wrap: anywhere; }
        .pdts-dash .kpi-value { font-size: 1.5rem; font-weight: 600; line-height: 1.15; font-variant-numeric: tabular-nums; }
        .pdts-dash .tone-red .kpi-value { color: #d93636; }
        /* Trend line: starts at the card edge so it always fits; indented under the label (icon + gap) when the card is wide enough. */
        .pdts-dash .kpi-trend { font-size: .75rem; color: var(--muted); margin-top: .5rem; white-space: nowrap; }
        @container (min-width: 225px) { .pdts-dash .kpi-trend { padding-left: 3.125rem; } }
        .pdts-dash .kpi-trend .good { color: #2f9e4f; font-weight: 500; }
        .pdts-dash .kpi-trend .bad { color: #d93636; font-weight: 500; }
        .pdts-dash .kpi-trend .flat { color: var(--muted); font-weight: 500; }

        /* Chart row: trend, status and workflow side by side from tablet width up. */
        .pdts-dash .chart-row { display: grid; grid-template-columns: 1.35fr 1.35fr 1fr; gap: .875rem; margin-bottom: 1rem; }
        @media (max-width: 991.98px) { .pdts-dash .chart-row { grid-template-columns: 1fr; } }
        .pdts-dash .chart-row > div { min-width: 0; }

        /* Panels */
        .pdts-dash .panel { padding: .875rem 1rem; height: 100%; }
        .pdts-dash .panel-title { font-size: .9375rem; font-weight: 600; margin: 0; display: flex; align-items: center; gap: .5rem; white-space: nowrap; min-width: 0; overflow: hidden; text-overflow: ellipsis; }
        .pdts-dash .panel-title .fas { color: #093f42; font-size: .9375rem; flex-shrink: 0; }
        .pdts-dash .panel-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: .75rem; gap: 1rem; }

        /* Donut with legend on the right */
        .pdts-dash .donut-wrap { display: flex; align-items: center; gap: .75rem; }
        @media (max-width: 575.98px) { .pdts-dash .donut-wrap { flex-direction: column; } }
        .pdts-dash .donut-chart { position: relative; width: 115px; height: 115px; flex-shrink: 0; }
        .pdts-dash .donut-centre { position: absolute; inset: 0; display: grid; place-content: center; text-align: center; pointer-events: none; }
        .pdts-dash .donut-centre strong { font-size: 1.25rem; font-weight: 600; line-height: 1.1; font-variant-numeric: tabular-nums; }
        .pdts-dash .donut-centre span { font-size: .625rem; font-weight: 500; }
        /* Each legend row stays on one line, with the full name and the count. */
        .pdts-dash .legend { list-style: none; padding: 0; margin: 0; flex: 1; min-width: 0; font-size: .75rem; }
        .pdts-dash .legend li { display: flex; align-items: center; gap: .5rem; padding: .25rem 0; white-space: nowrap; }
        .pdts-dash .legend .swatch { width: .6875rem; height: .6875rem; border-radius: 2px; flex-shrink: 0; }
        .pdts-dash .legend .name { flex-shrink: 0; } /* full name, never shortened */
        .pdts-dash .legend .count { margin-left: auto; padding-left: .75rem; font-variant-numeric: tabular-nums; flex-shrink: 0; }

        /* Workflow steps */
        .pdts-dash .steps { list-style: none; padding: 0; margin: 0; }
        .pdts-dash .steps li { display: flex; align-items: center; gap: .75rem; padding: .3rem .875rem .3rem .75rem; border-radius: .5rem; position: relative; font-size: .8125rem;
            background: linear-gradient(90deg, #e2eaed 0%, #eef3f5 100%); }
        .pdts-dash .steps li.is-done { background: linear-gradient(90deg, #dcefdf 0%, #ecf6ed 100%); font-weight: 500; }
        .pdts-dash .steps li.is-done .step-total { color: #2f8a48; font-weight: 600; }
        .pdts-dash .steps li + li { margin-top: .625rem; }
        /* Connector: a small arrowhead under each number circle. */
        .pdts-dash .steps li + li::before { content: "\f063"; font-family: "Font Awesome 5 Free"; font-weight: 900; position: absolute; left: 1.2rem; top: -.65rem; font-size: .4375rem; line-height: 1; color: #4b5563; }
        .pdts-dash .step-no { width: 1.375rem; height: 1.375rem; border-radius: 50%; background: var(--tone); color: #fff; display: grid; place-items: center; font-weight: 600; font-size: .6875rem; flex-shrink: 0;
            box-shadow: 0 0 0 2px #fff, 0 1px 3px rgba(16, 24, 40, .25); }
        .pdts-dash .step-label { min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .pdts-dash .step-total { margin-left: auto; font-weight: 500; font-variant-numeric: tabular-nums; }

        /* Bottom row: recent documents and quick actions side by side from tablet width up. */
        .pdts-dash .bottom-row { display: grid; grid-template-columns: minmax(0, 2.75fr) minmax(0, 1fr); gap: .875rem; margin-bottom: 1rem; }
        .pdts-dash .bottom-row.no-actions { grid-template-columns: minmax(0, 1fr); }
        @media (max-width: 991.98px) { .pdts-dash .bottom-row { grid-template-columns: minmax(0, 1fr); } }

        /* Recent documents table */
        /* Fixed columns: long names end in "…" (full text on hover) instead of widening the table.
           Below 520px the table scrolls sideways rather than squashing. */
        .pdts-dash .recent-table { width: 100%; min-width: 520px; table-layout: fixed; font-size: .78rem; border-collapse: separate; border-spacing: 0; }
        .pdts-dash .recent-table th, .pdts-dash .recent-table td { overflow: hidden; text-overflow: ellipsis; }
        .pdts-dash .recent-table .col-ref { width: 26%; }
        .pdts-dash .recent-table .col-subject { width: 22%; }
        .pdts-dash .recent-table .col-holder { width: 22%; }
        .pdts-dash .recent-table .col-status { width: 18%; }
        .pdts-dash .recent-table .col-actions { width: 12%; }
        .pdts-dash .recent-table td:last-child, .pdts-dash .recent-table td.status, .pdts-dash .recent-table td.ref { overflow: visible; text-overflow: clip; } /* never shortened */
        .pdts-dash .recent-table th { font-weight: 600; background: #f1f4f6; padding: .5rem .75rem; white-space: nowrap; border: 0; }
        .pdts-dash .recent-table th:first-child { border-radius: .375rem 0 0 .375rem; padding-left: 1.75rem; }
        .pdts-dash .recent-table th:last-child { border-radius: 0 .375rem .375rem 0; }
        .pdts-dash .recent-table td { padding: .45rem .75rem; border-bottom: 1px solid var(--line); vertical-align: middle; white-space: nowrap; }
        .pdts-dash .recent-table tbody tr:last-child td { border-bottom: 0; }
        .pdts-dash .recent-table tbody tr:hover td { background: #fafbfc; }
        .pdts-dash .recent-table .ref { font-variant-numeric: tabular-nums; }
        .pdts-dash .recent-table .ref .far { color: #374151; margin-right: .5rem; font-size: .9375rem; }
        .pdts-dash .status-badge { display: inline-block; white-space: nowrap; vertical-align: middle; text-align: center; padding: .15rem .5rem; border-radius: .375rem; font-size: .75rem; font-weight: 500; border: 1px solid transparent; }
        .pdts-dash .badge-draft     { background: #f1f3f5; color: #5b6673; border-color: #dde2e7; }
        .pdts-dash .badge-registered{ background: #e3f3f0; color: #093f42; border-color: #b9e0d9; }
        .pdts-dash .badge-pending   { background: #fdf1d9; color: #a35d12; border-color: #f6d9a3; }
        .pdts-dash .badge-received  { background: #e3eefd; color: #1f5fc0; border-color: #bcd5f8; }
        .pdts-dash .badge-completed { background: #e2f5e6; color: #2a8a43; border-color: #b7e2c1; }
        .pdts-dash .badge-overdue   { background: #fde6e6; color: #c62828; border-color: #f6bcbc; }
        .pdts-dash .row-action { color: var(--ink); padding: 0 .375rem; background: none; border: 0; line-height: 1; }
        .pdts-dash .row-action:hover { color: #093f42; }
        .pdts-dash .view-all { color: #d9813a; font-weight: 500; font-size: .8125rem; white-space: nowrap; }
        .pdts-dash .view-all:hover { color: #b8672a; text-decoration: none; }
        .pdts-dash .empty { padding: 1.5rem .25rem; color: var(--muted); }

        /* Quick actions */
        .pdts-dash .qa-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
        .pdts-dash .qa { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .5rem; min-height: 84px; padding: .75rem .375rem; border-radius: .5rem;
            text-align: center; font-weight: 500; font-size: .8125rem; line-height: 1.2; transition: transform .15s, box-shadow .15s; box-shadow: 0 1px 2px rgba(16, 24, 40, .08); }
        .pdts-dash .qa .fas { font-size: 1.5rem; }
        .pdts-dash .qa:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(16, 24, 40, .15); text-decoration: none; }
        .pdts-dash .qa-orange { background: linear-gradient(135deg, #d98a4a, #c87332); color: #fff; }
        .pdts-dash .qa-teal   { background: linear-gradient(135deg, #0f5257, #093f42); color: #fff; }
        .pdts-dash .qa-soft   { background: #e5eeef; color: var(--ink); }
        .pdts-dash .qa-soft .fas { color: #1f2937; }
        .pdts-dash .qa-orange:hover, .pdts-dash .qa-teal:hover { color: #fff; }
        .pdts-dash .qa-soft:hover { color: var(--ink); }
        @media (prefers-reduced-motion: reduce) {
            .pdts-dash .kpi, .pdts-dash .qa { transition: none; }
            .pdts-dash .kpi:hover, .pdts-dash .qa:hover { transform: none; }
        }
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

    {{-- KPI cards --}}
    <div class="kpi-grid" data-scope="{{ $board['organisationWide'] ? 'organisation' : 'mine' }}">
        @foreach ($kpis as $kpi)
            @php
                $up = $kpi['trend'] > 0;
                $flat = $kpi['trend'] === 0;
                $good = $up === ($kpi['goodWhen'] === 'up');
            @endphp
            <div class="dash-card kpi tone-{{ $kpi['tone'] }}" data-tile="{{ $kpi['key'] }}">
                <div class="kpi-top">
                    <span class="kpi-icon"><i class="fas {{ $kpi['icon'] }}" aria-hidden="true"></i></span>
                    <div style="min-width: 0;">
                        <div class="kpi-label">{{ $kpi['label'] }}</div>
                        <div class="kpi-value">{{ number_format($kpi['value']) }}</div>
                    </div>
                </div>
                <div class="kpi-trend">
                    <span class="{{ $flat ? 'flat' : ($good ? 'good' : 'bad') }}">
                        @unless ($flat)
                            <i class="fas {{ $up ? 'fa-arrow-up' : 'fa-arrow-down' }}" aria-hidden="true"></i>
                        @endunless
                        {{ $up ? '+' : '' }}{{ $kpi['trend'] }}%
                    </span>
                    from last 30 days
                </div>
                @if ($kpi['url'])
                    <a href="{{ $kpi['url'] }}" class="stretched-link" aria-label="{{ $kpi['label'] }}"></a>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Charts --}}
    <div class="chart-row">
        <div>
            <div class="dash-card panel">
                <div class="panel-head mb-1">
                    <h4 class="panel-title"><i class="fas fa-chart-bar"></i>Document Movement Trend</h4>
                </div>
                <div wire:ignore>
                    <div id="pdts-trend-chart" style="height: 185px;" role="img" aria-label="Documents registered and completed over the last 7 days"></div>
                </div>
            </div>
        </div>

        <div>
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
                                <span class="name" title="{{ $statusLabels[$row['status']->value] }}">{{ $statusLabels[$row['status']->value] }}</span>
                                <span class="count">
                                    {{ number_format($row['total']) }} ({{ $board['total'] > 0 ? round($row['total'] / $board['total'] * 100) : 0 }}%)
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>

        <div>
            <div class="dash-card panel">
                <div class="panel-head">
                    <h4 class="panel-title"><i class="fas fa-sitemap"></i>Document Workflow</h4>
                </div>
                <ol class="steps">
                    @foreach ($board['workflow'] as $i => $step)
                        <li class="tone-{{ ['teal', 'orange', 'blue', 'green'][$i] }} {{ $loop->last ? 'is-done' : '' }}">
                            <span class="step-no">{{ $i + 1 }}</span>
                            <span class="step-label">{{ $step['label'] }}</span>
                            <span class="step-total">{{ number_format($step['total']) }}</span>
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>
    </div>

    {{-- Recent documents and quick actions --}}
    <div class="bottom-row {{ $quickActions ? '' : 'no-actions' }}">
        <div>
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
                                    <th class="col-ref">Reference No.</th>
                                    <th class="col-subject">Subject</th>
                                    <th class="col-holder">Current Holder</th>
                                    <th class="col-status">Status</th>
                                    <th class="col-actions text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($board['recent'] as $document)
                                    @php
                                        $overdue = $document->pendingMovement && $document->pendingMovement->forwarded_at?->lte($cutoff);
                                        [$badgeText, $badgeClass] = $overdue ? ['Overdue', 'badge-overdue'] : ($badges[$document->status] ?? [$document->status, 'badge-draft']);
                                    @endphp
                                    <tr wire:key="recent-{{ $document->id }}">
                                        <td class="ref"><i class="far fa-file-alt" aria-hidden="true"></i>{{ $document->reference_number }}</td>
                                        <td title="{{ $document->title }}">{{ $document->title }}</td>
                                        <td title="{{ $document->currentCustodian?->name }}">{{ $document->currentCustodian?->name ?? '—' }}</td>
                                        <td class="status"><span class="status-badge {{ $badgeClass }}">{{ $badgeText }}</span></td>
                                        <td class="text-center">
                                            <a href="{{ route('movements.history', $document) }}" class="row-action" title="View history" aria-label="View {{ $document->reference_number }}">
                                                <i class="far fa-eye"></i>
                                            </a>
                                            <span class="dropdown">
                                                <button type="button" class="row-action" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" aria-label="More actions for {{ $document->reference_number }}">
                                                    <i class="fas fa-ellipsis-h"></i>
                                                </button>
                                                <div class="dropdown-menu dropdown-menu-right">
                                                    <a class="dropdown-item" href="{{ route('movements.history', $document) }}"><i class="fas fa-route mr-2"></i>Movement history</a>
                                                    @if ($mine['canViewDocuments'])
                                                        <a class="dropdown-item" href="{{ route('pdts.documents.show', $document) }}"><i class="fas fa-info-circle mr-2"></i>Document details</a>
                                                    @endif
                                                </div>
                                            </span>
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
            <div>
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
</div>

@script
<script>
    const trend = @js($board['trendChart']);
    const statuses = @js($donut);

    if (window.Highcharts) {
        Highcharts.chart('pdts-trend-chart', {
            chart: { type: 'areaspline', backgroundColor: 'transparent', style: { fontFamily: 'Poppins, sans-serif' }, spacing: [8, 4, 4, 0] },
            title: { text: null },
            credits: { enabled: false },
            accessibility: { enabled: false },
            legend: { align: 'right', verticalAlign: 'top', symbolWidth: 18, symbolHeight: 8, symbolRadius: 4, squareSymbol: false,
                      itemStyle: { fontWeight: '400', color: '#1f2937', fontSize: '11px' } },
            // Dates stay level; on a narrow panel they shrink rather than tilt.
            xAxis: { categories: trend.labels, lineColor: '#d5dbe1', tickLength: 0, gridLineWidth: 1, gridLineColor: '#eef1f4',
                     labels: { autoRotation: false, rotation: 0, style: { color: '#1f2937', fontSize: '11px', textOverflow: 'none', whiteSpace: 'nowrap' } } },
            // softMax keeps a readable scale when every day is zero.
            yAxis: { title: { text: null }, allowDecimals: false, min: 0, softMax: 5, tickAmount: 6, gridLineColor: '#eef1f4', labels: { style: { color: '#1f2937', fontSize: '11px' } } },
            tooltip: { shared: true, borderRadius: 8 },
            plotOptions: { areaspline: { lineWidth: 2, marker: { symbol: 'circle', radius: 4, lineWidth: 1.5, fillColor: '#fff', lineColor: null } } },
            series: [
                { name: 'Registered', data: trend.registered, color: '#093f42',
                  fillColor: { linearGradient: { x1: 0, y1: 0, x2: 0, y2: 1 }, stops: [[0, 'rgba(9,63,66,.22)'], [1, 'rgba(9,63,66,.02)']] } },
                { name: 'Completed', data: trend.completed, color: '#d9813a',
                  fillColor: { linearGradient: { x1: 0, y1: 0, x2: 0, y2: 1 }, stops: [[0, 'rgba(217,129,58,.22)'], [1, 'rgba(217,129,58,.02)']] } },
            ],
        });

        Highcharts.chart('pdts-status-chart', {
            chart: { type: 'pie', backgroundColor: 'transparent', margin: [0, 0, 0, 0], spacing: [0, 0, 0, 0] },
            title: { text: null },
            credits: { enabled: false },
            accessibility: { enabled: false },
            tooltip: { enabled: statuses.length > 0, pointFormat: '<b>{point.y}</b> ({point.percentage:.0f}%)' },
            plotOptions: { pie: { innerSize: '70%', borderWidth: 2, borderColor: '#fff', dataLabels: { enabled: false }, size: '100%', startAngle: 0 } },
            series: [{
                name: 'Documents',
                // An empty grey ring when there is nothing to show, with no hover.
                data: statuses.length ? statuses : [{ name: '', y: 1, color: '#eef1f4' }],
                enableMouseTracking: statuses.length > 0,
                states: { inactive: { opacity: 1 } },
            }],
        });
    }
</script>
@endscript
