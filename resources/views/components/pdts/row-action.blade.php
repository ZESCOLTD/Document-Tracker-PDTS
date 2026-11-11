{{-- One item in <x-pdts.row-actions>. Pass wire:click (and wire:confirm) as attributes. --}}
@props(['icon' => null, 'danger' => false])

<button type="button" {{ $attributes->class(['dropdown-item', 'text-danger' => $danger]) }}>
    @if ($icon)<i class="fas {{ $icon }} fa-fw mr-2 {{ $danger ? '' : 'text-muted' }}"></i>@endif{{ $slot }}
</button>
