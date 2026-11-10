{{-- Same card as the Users page KPI cards. Expects: label, value, colour, icon, note, noteClass, url (optional). --}}
<div class="card elevation-2 border-0 h-100 stat-card">
    <div class="card-body d-flex justify-content-between align-items-center">
        <div>
            <small class="text-muted text-uppercase">{{ $label }}</small>
            <h3 class="mb-0 font-weight-bold" style="color: {{ $colour }};">{{ $value }}</h3>
            <small class="{{ $noteClass ?? 'text-muted' }}">{{ $note }}</small>
        </div>

        <i class="fas {{ $icon }}" style="font-size: 2rem; color: {{ $colour }};" aria-hidden="true"></i>
    </div>

    @if (! empty($url))
        <a href="{{ $url }}" class="stretched-link" aria-label="{{ $label }}"></a>
    @endif
</div>
