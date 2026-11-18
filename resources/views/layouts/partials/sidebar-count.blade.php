{{-- Orange count bubble on a sidebar link. Hidden when there is nothing waiting. Expects: count, label. --}}
@if ($count > 0)
    <span class="pdts-count" data-count="{{ $count }}">
        {{ $count > 99 ? '99+' : $count }}<span class="sr-only"> {{ $label }}</span>
    </span>
@endif
