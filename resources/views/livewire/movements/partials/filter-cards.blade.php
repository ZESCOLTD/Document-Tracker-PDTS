@once
    <style>
        .pdts-filter-cards { display: flex; flex-wrap: wrap; gap: .75rem; margin-bottom: 1rem; }
        .pdts-filter-card {
            --filter-accent: #176b65; --filter-tint: #eaf5f3; --filter-border: #8ebeb8;
            display: grid; grid-template-columns: 34px minmax(0, 1fr); align-items: center; gap: .75rem;
            flex: 1 1 155px; max-width: 220px; min-width: 0; min-height: 82px; padding: .75rem;
            border: 1px solid #dfe5e7; border-radius: 6px; background: #fff; color: #343e43;
            text-align: left; letter-spacing: 0; box-shadow: 0 1px 2px rgba(25, 45, 50, .04);
            transition: background-color .15s, border-color .15s;
        }
        .pdts-filter-card--amber { --filter-accent: #8c600e; --filter-tint: #fff7e6; --filter-border: #d9b96f; }
        .pdts-filter-card--blue { --filter-accent: #28699c; --filter-tint: #edf5fc; --filter-border: #93bada; }
        .pdts-filter-card--green { --filter-accent: #367144; --filter-tint: #eff7ee; --filter-border: #a0c49f; }
        .pdts-filter-card:hover { border-color: var(--filter-border); background: var(--filter-tint); }
        .pdts-filter-card[aria-pressed="true"] {
            border-color: var(--filter-accent); background: var(--filter-tint);
            box-shadow: inset 0 -3px 0 var(--filter-accent);
        }
        .pdts-filter-card:focus-visible { outline: 3px solid var(--filter-border); outline-offset: 3px; }
        .pdts-filter-card:disabled { cursor: progress; opacity: .7; }
        .pdts-filter-icon {
            display: flex; align-items: center; justify-content: center; width: 34px; height: 34px;
            border-radius: 5px; background: var(--filter-tint); color: var(--filter-accent); font-size: 15px;
        }
        .pdts-filter-count { display: block; font-size: 22px; font-weight: 700; line-height: 1.2; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
        .pdts-filter-label { display: block; margin-top: 3px; font-size: 12px; font-weight: 600; line-height: 1.35; overflow-wrap: anywhere; }
        @media (max-width: 575px) {
            .pdts-filter-cards { gap: .5rem; }
            .pdts-filter-card { flex-basis: calc(50% - .5rem); max-width: none; }
        }
        @media (prefers-reduced-motion: reduce) { .pdts-filter-card { transition: none; } }
    </style>
@endonce

@php($selectedFilter = array_key_exists($statusFilter, $filterCards) ? $statusFilter : array_key_first($filterCards))
<div class="pdts-filter-cards" role="group" aria-label="Filter documents">
    @foreach ($filterCards as $filterValue => $filterCard)
        <button type="button" class="pdts-filter-card pdts-filter-card--{{ $filterCard['tone'] ?? 'teal' }}"
                wire:key="filter-{{ $filterValue }}" wire:click="$set('{{ $filterProperty ?? 'statusFilter' }}', '{{ $filterValue }}')"
                wire:loading.attr="disabled" wire:target="{{ $filterProperty ?? 'statusFilter' }}"
                aria-pressed="{{ $selectedFilter === $filterValue ? 'true' : 'false' }}">
            <span class="pdts-filter-icon" aria-hidden="true"><i class="fas {{ $filterCard['icon'] }}"></i></span>
            <span>
                <span class="pdts-filter-count" data-filter-count="{{ $filterValue }}">{{ number_format($filterCard['count']) }}</span>
                <span class="pdts-filter-label">{{ $filterCard['label'] }}</span>
            </span>
        </button>
    @endforeach
</div>