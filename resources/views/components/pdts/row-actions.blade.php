{{--
    Actions dropdown for a table row. Put <x-pdts.row-action> items inside.
    The table's .table-responsive wrapper needs the "actions-overflow" class so the menu is not clipped.
--}}
@props(['label' => 'Actions'])

<div class="dropdown d-inline-block">
    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-toggle="dropdown" data-boundary="viewport" aria-haspopup="true" aria-expanded="false">
        {{ $label }}
    </button>
    <div class="dropdown-menu dropdown-menu-right shadow-sm">
        {{ $slot }}
    </div>
</div>
