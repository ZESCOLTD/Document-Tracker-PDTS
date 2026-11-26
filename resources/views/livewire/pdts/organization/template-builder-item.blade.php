{{-- One item on the builder's page (all layouts). Expects $i, and $cellPos ([row, column, position]) in the Custom layout. --}}
@php
    use App\Services\Pdts\Templates\TemplateDesigner;

    $item = $items[$i];
    $style = $item['style'] ?? TemplateDesigner::STYLE;
    $styleClass = 'tb-s-'.$style['align'].($style['bold'] ? ' font-weight-bold' : '').($style['italic'] ? ' font-italic' : '').($style['caps'] ? ' text-uppercase' : '')
        .' tb-s-'.$style['size'].($style['box'] !== 'none' ? ' tb-s-'.$style['box'] : '');
    $label = $item['label'] !== '' ? $item['label'] : ($item['kind'] === 'question' ? 'Question' : '');
    $short = ($item['kind'] === 'standard' && $item['name'] !== 'subject') || ($item['kind'] === 'question' && ! TemplateDesigner::isParagraph($item));
    $req = in_array($item['kind'], ['standard', 'question'], true) && $item['required'];
    // What a standard line shows where the author writes: an example for the template's document type, not the rule (a red * marks a required line).
    // The Custom layout leaves them blank (just the line); the memo shows the examples.
    $standardHint = $isCustom ? "\u{00A0}" : (isset($standardHints[$item['name'] ?? '']) ? 'e.g. '.$standardHints[$item['name']] : 'Filled in by the author');
    // A table question shows its columns, with an empty row.
    $tablePreview = $item['kind'] === 'question' && $item['type'] === 'table'
        ? '<table class="tb-mini"><tr>'.collect(explode(',', (string) ($item['columns'] ?? '')))->map(fn ($c) => '<th>'.e(trim($c)).'</th>')->implode('').'</tr><tr>'.str_repeat('<td>&nbsp;</td>', max(1, count(array_filter(explode(',', (string) ($item['columns'] ?? '')), 'trim')))).'</tr></table>'
        : null;
@endphp
@unless ($cellPos)
    <div x-show="over === {{ $i }} && drag" class="tb-drop-line" x-cloak></div>
@endunless
<div wire:key="item-{{ $item['id'] }}"
    class="tb-item {{ $selected === $i ? 'is-selected' : '' }} {{ $hasError($i) ? 'has-error' : '' }}"
    :class="{ 'is-dragging': drag && drag.from === 'item' && drag.index === {{ $i }} }"
    draggable="true"
    x-on:dragstart="start($event, { from: 'item', index: {{ $i }} })" x-on:dragend="end()"
    @if ($cellPos)
        x-on:dragover.prevent.stop="hoverCell($event, {{ $cellPos[0] }}, {{ $cellPos[1] }}, {{ $cellPos[2] }})"
    @else
        x-on:dragover.prevent="hover($event, {{ $i }})"
    @endif
    wire:click="select({{ $i }})" data-test="item-{{ $i }}">
    <div class="tb-actions" x-on:click.stop>
        <button type="button" title="Move up" aria-label="Move up" wire:click="moveUp({{ $i }})" @disabled(! $cellPos && $i === 0)><i class="fas fa-arrow-up"></i></button>
        <button type="button" title="Move down" aria-label="Move down" wire:click="moveDown({{ $i }})" @disabled(! $cellPos && $i === count($items) - 1)><i class="fas fa-arrow-down"></i></button>
        @if (in_array($item['kind'], ['question', 'section', 'note', 'text', 'line', 'space', 'pagebreak', 'signline'], true))
            <button type="button" title="Duplicate" aria-label="Duplicate" wire:click="duplicate({{ $i }})"><i class="far fa-copy"></i></button>
        @endif
        <button type="button" title="Remove" aria-label="Remove" wire:click="remove({{ $i }})"><i class="far fa-trash-alt"></i></button>
    </div>

    @if (in_array($item['kind'], ['line', 'space', 'pagebreak'], true))
        {{-- A line or a space, in any layout --}}
        @if ($item['kind'] === 'line')
            <div class="d-flex align-items-center" style="gap: .375rem;"><span class="tb-handle"><i class="fas fa-grip-vertical"></i></span><div style="flex: 1; border-bottom: {{ \App\Services\Pdts\Templates\TemplateDesigner::LINE_WEIGHTS[$item['weight'] ?? 'thin'][2] ?? 1 }}px solid #111;" data-test="line-{{ $item['weight'] ?? 'thin' }}"></div></div>
        @elseif ($item['kind'] === 'pagebreak')
            <div class="d-flex align-items-center small text-muted" style="gap: .375rem; height: 1.75rem;"><span class="tb-handle"><i class="fas fa-grip-vertical"></i></span><span style="flex: 1; border-top: 2px dashed #9aa5a5; text-align: center; line-height: 0;"><span style="background: #fff; padding: 0 .5rem;"><i class="fas fa-cut mr-1"></i>page break: a new page starts here</span></span></div>
        @else
            <div class="d-flex align-items-center small text-muted" style="gap: .375rem; height: 1.75rem;"><span class="tb-handle"><i class="fas fa-grip-vertical"></i></span><span style="flex: 1; border: 1px dashed #c9d6d6; text-align: center; border-radius: .25rem;">space</span></div>
        @endif
    @elseif ($item['kind'] === 'signline')
        {{-- Signed by hand on paper --}}
        <div class="{{ $isCustom ? $styleClass : '' }}" style="font-weight: normal; font-style: normal; text-transform: none;">
            <div class="font-weight-bold small"><span class="tb-handle"><i class="fas fa-grip-vertical"></i></span>{{ rtrim($item['label'], ': ') }}</div>
            <div style="border-bottom: 1px solid #111; height: 2rem; max-width: 16rem; {{ ($style['align'] ?? 'left') === 'right' ? 'margin-left: auto;' : (($style['align'] ?? 'left') === 'center' ? 'margin: 0 auto;' : '') }}"></div>
            <div class="small text-muted">Name, signature and date (signed by hand)</div>
        </div>
    @elseif ($isCustom)
        {{-- Custom layout: the item in its own style --}}
        <div class="{{ $styleClass }}">
            @if ($short || $item['kind'] === 'standard')
                @if ($style['label'] === 'above')
                    <div class="font-weight-bold small"><span class="tb-handle"><i class="fas fa-grip-vertical"></i></span>{{ rtrim($label, ':') }}@if ($req)<span class="tb-req">*</span>@endif</div>
                    <div class="tb-blank" style="display: block;">{{ $item['kind'] === 'question' ? $answerHint($item) : $standardHint }}</div>
                @else
                    <div class="tb-line" style="justify-content: inherit;"><span class="tb-handle"><i class="fas fa-grip-vertical"></i></span>@if ($style['label'] === 'inline')<span class="font-weight-bold">{{ rtrim($label, ':') }}:@if ($req)<span class="tb-req">*</span>@endif</span>@elseif ($style['label'] === 'plain')<span>{{ rtrim($label, ' :,') }}@if ($req)<span class="tb-req">*</span>@endif</span>@endif<span class="tb-blank">{{ $item['kind'] === 'question' ? $answerHint($item) : $standardHint }}</span></div>
                @endif
            @elseif ($item['kind'] === 'question')
                @if ($style['label'] !== 'hidden' && $item['show_label'])<div class="font-weight-bold small"><span class="tb-handle"><i class="fas fa-grip-vertical"></i></span>{{ $label }}@if ($req)<span class="tb-req">*</span>@endif</div>@endif
                @if ($tablePreview){!! $tablePreview !!}@else<div class="text-muted font-italic small" style="border: 1px dotted #b8c4c4; border-radius: .25rem; padding: .5rem; min-height: 3rem;">{{ $label }}</div>@endif
            @elseif ($item['kind'] === 'section')
                <div><span class="tb-handle"><i class="fas fa-grip-vertical"></i></span>{{ $label ?: 'Section' }}</div>
            @elseif (in_array($item['kind'], ['note', 'text'], true))
                <div style="white-space: pre-line;"><span class="tb-handle"><i class="fas fa-grip-vertical"></i></span>{{ $item['text'] }}</div>
            @elseif ($item['kind'] === 'signature')
                @if ($item['label'] !== '')<div class="font-weight-bold small">{{ rtrim($item['label'], ': ') }}</div>@endif
                <div class="text-muted" style="font-family: 'Brush Script MT', cursive; font-size: 1.5rem;"><span class="tb-handle" style="font-size: .875rem;"><i class="fas fa-grip-vertical"></i></span><i class="fas fa-signature"></i> signature</div>
                <div class="font-weight-bold small">SIGNATORY'S NAME</div>
                @if (! empty($item['job_title']))<div class="small">Job title</div>@endif
            @endif
        </div>
    @elseif (in_array($item['kind'], ['text'], true))
        <div style="white-space: pre-line;"><span class="tb-handle"><i class="fas fa-grip-vertical"></i></span>{{ $item['text'] }}</div>
    @elseif ($isReport && $item['kind'] !== 'signature')
        {{-- Report style: details in a table, numbered sections and paragraphs --}}
        @if ($short)
            <div class="tb-row first"><div><span class="tb-handle"><i class="fas fa-grip-vertical"></i></span>{{ rtrim($label, ':') }}@if ($req)<span class="tb-req">*</span>@endif</div><div class="text-muted font-italic">{{ $item['kind'] === 'question' ? $answerHint($item) : 'Filled in by the author' }}</div></div>
        @elseif ($item['kind'] === 'standard')
            <div class="tb-line font-weight-bold text-uppercase mt-2"><span class="tb-handle"><i class="fas fa-grip-vertical"></i></span><span class="tb-blank" style="font-weight: 700;">The subject, in capitals{{ $req ? ' *' : '' }}</span></div>
        @elseif ($item['kind'] === 'section')
            <div class="font-weight-bold mt-2 text-uppercase" data-test="report-number"><span class="tb-handle"><i class="fas fa-grip-vertical"></i></span>{{ $numbers[$i] ?? '' }}.&nbsp; {{ $label ?: 'Section' }}</div>
        @elseif ($item['kind'] === 'note')
            <div class="small font-italic"><span class="tb-handle"><i class="fas fa-grip-vertical"></i></span>{{ $item['text'] }}</div>
        @else
            @if ($item['show_label'])<div class="font-weight-bold small mt-1"><span class="tb-handle"><i class="fas fa-grip-vertical"></i></span>{{ $numbers[$i] ?? '' }}{{ str_contains($numbers[$i] ?? '', '.') ? '' : '.' }}&nbsp; {{ $label }}@if ($req)<span class="tb-req">*</span>@endif</div>@endif
            @if ($tablePreview){!! $tablePreview !!}@else<div class="text-muted font-italic small" style="border: 1px dotted #b8c4c4; border-radius: .25rem; padding: .5rem; min-height: 3rem;">@unless ($item['show_label'])<span class="tb-handle"><i class="fas fa-grip-vertical"></i></span>@endunless {{ $item['type'] === 'rich' ? 'Formatted text' : 'Paragraphs' }} written by the author ({{ $label }})</div>@endif
        @endif
    @elseif ($isForm && $item['kind'] !== 'signature')
        {{-- Form style: rows of a table --}}
        @if ($item['kind'] === 'section')
            <div class="tb-row full section first"><div>{{ $label ?: 'Section' }}</div></div>
        @elseif ($item['kind'] === 'note')
            <div class="tb-row full first"><div style="background: #fff; font-weight: 400; font-style: italic;">{{ $item['text'] }}</div></div>
        @elseif ($short || ($item['kind'] === 'standard'))
            <div class="tb-row first"><div><span class="tb-handle"><i class="fas fa-grip-vertical"></i></span>{{ $label }}@if ($req)<span class="tb-req">*</span>@endif</div><div class="text-muted font-italic">{{ $item['kind'] === 'question' ? $answerHint($item) : 'Filled in by the author' }}</div></div>
        @else
            @if ($item['show_label'])<div class="tb-row full first"><div>{{ $label }}@if ($req)<span class="tb-req">*</span>@endif</div></div>@endif
            @if ($tablePreview){!! $tablePreview !!}@else<div class="tb-row full {{ $item['show_label'] ? '' : 'first' }}"><div style="background: #fff; font-weight: 400; min-height: 3.5rem;" class="text-muted font-italic">{{ $item['type'] === 'rich' ? 'Formatted text written by the author (bold, italic, lists)' : 'Paragraphs written by the author' }}</div></div>@endif
        @endif
    @elseif ($isLetter && ! in_array($item['kind'], ['text'], true))
        {{-- Letter style, as on the ZESCO letter --}}
        @php $handle = '<span class="tb-handle"><i class="fas fa-grip-vertical"></i></span>'; @endphp
        @if ($item['kind'] === 'standard' && $item['name'] === 'office_reference')
            <div class="tb-line"><span class="tb-l font-weight-bold" style="min-width: 0;">{!! $handle !!}{{ rtrim($label, ': ') }}:@if ($req)<span class="tb-req">*</span>@endif</span><span class="tb-blank font-weight-bold">{{ $standardHint }}</span></div>
        @elseif ($item['kind'] === 'standard' && $item['name'] === 'date')
            <div class="tb-line">{!! $handle !!}<span class="tb-blank">{{ $standardHint }}</span></div>
        @elseif ($item['kind'] === 'standard' && $item['name'] === 'subject')
            <div class="tb-line font-weight-bold text-uppercase">{!! $handle !!}<span class="tb-blank" style="font-weight: 700;">The subject of the letter, in capitals{{ $req ? ' *' : '' }}</span></div>
        @elseif ($item['kind'] === 'standard' && $item['name'] === 'cc')
            <div class="tb-line small">{!! $handle !!}<span>{{ rtrim($label, ': ') }}:</span><span class="tb-blank">{{ $standardHint }}</span></div>
        @elseif ($item['kind'] === 'standard')
            <div class="tb-line"><span class="tb-l">{!! $handle !!}{{ rtrim($label, ': ') }}:@if ($req)<span class="tb-req">*</span>@endif</span><span class="tb-blank">{{ $standardHint }}</span></div>
        @elseif (TemplateDesigner::isGreeting($item))
            <div class="tb-line">{!! $handle !!}<span>{{ rtrim($label, ' :,') }}@if ($req)<span class="tb-req">*</span>@endif</span><span class="tb-blank">{{ $item['help'] !== '' ? $item['help'] : 'e.g. Mr. Banda' }}</span></div>
        @elseif ($item['kind'] === 'question' && $short)
            <div class="tb-line"><span class="tb-l">{!! $handle !!}{{ rtrim($label, ':') }}:@if ($req)<span class="tb-req">*</span>@endif</span><span class="tb-blank">{{ $answerHint($item) }}</span></div>
        @elseif ($item['kind'] === 'question')
            @if ($item['show_label'])<div class="font-weight-bold small mt-1">{!! $handle !!}{{ $label }}@if ($req)<span class="tb-req">*</span>@endif</div>@endif
            @if ($tablePreview){!! $tablePreview !!}@else
                <div class="text-muted font-italic small" style="border: 1px dotted #b8c4c4; border-radius: .25rem; padding: .375rem .5rem; min-height: {{ mb_strtolower($label) === 'addressee' ? '2.5rem' : '3.5rem' }}; white-space: pre-line;">@unless ($item['show_label']){!! $handle !!}@endunless{{ mb_strtolower($label) === 'addressee' ? "Mr./Ms. Full Name\nPosition / Organisation\nAddress or phone number\nTOWN" : $label.' (written by the author)' }}{{ $req && ! $item['show_label'] ? ' *' : '' }}</div>
            @endif
        @elseif ($item['kind'] === 'section')
            <div class="font-weight-bold mt-2 text-uppercase">{!! $handle !!}{{ $label ?: 'Section' }}</div>
        @elseif ($item['kind'] === 'note')
            <div class="small font-italic">{!! $handle !!}{{ $item['text'] }}</div>
        @elseif ($item['kind'] === 'signature')
            <div class="mt-2">
                <div>{!! $handle !!}{{ $item['label'] !== '' ? $item['label'] : 'Yours faithfully,' }}</div>
                <div class="font-weight-bold">{{ mb_strtoupper($organisation) }}</div>
                <div class="text-muted" style="font-family: 'Brush Script MT', cursive; font-size: 1.5rem;"><i class="fas fa-signature"></i> signature</div>
                <div class="font-weight-bold small">FULL NAME</div>
                @if (! empty($item['job_title']))<div class="small">Job title</div>@endif
            </div>
        @endif
    @else
        {{-- Memo style (and the signature block) --}}
        @switch($item['kind'])
            @case('standard')
                @if ($item['name'] === 'subject' && $letterhead)
                    <div class="tb-line font-weight-bold text-uppercase"><span class="tb-handle"><i class="fas fa-grip-vertical"></i></span><span class="tb-blank" style="font-weight: 700;">The subject, in capitals{{ $req ? ' *' : '' }}</span></div>
                @elseif ($letterhead)
                    {{-- As in the ZESCO memo (and its PDF): a rule under the CC line. --}}
                    <div class="tb-line"><span class="tb-l" style="min-width: 0;"><span class="tb-handle"><i class="fas fa-grip-vertical"></i></span>{{ rtrim($label, ':') }}:@if ($req)<span class="tb-req">*</span>@endif</span><span class="tb-blank">{{ $standardHint }}</span></div>
                @elseif ($item['name'] === 'subject')
                    <div class="tb-line" style="border-bottom: 2px solid #111; padding-bottom: .375rem;"><span class="tb-l" style="min-width: 0;"><span class="tb-handle"><i class="fas fa-grip-vertical"></i></span>{{ rtrim($label, ':') }}:</span><span class="tb-blank">Subject{{ $req ? ' *' : '' }}</span></div>
                @else
                    <div class="tb-line"><span class="tb-l"><span class="tb-handle"><i class="fas fa-grip-vertical"></i></span>{{ rtrim($label, ':') }}:@if ($req)<span class="tb-req">*</span>@endif</span><span class="tb-blank">{{ $standardHint }}</span></div>
                @endif
                @break
            @case('question')
                @if ($short)
                    <div class="tb-line"><span class="tb-l"><span class="tb-handle"><i class="fas fa-grip-vertical"></i></span>{{ rtrim($label, ':') }}:@if ($req)<span class="tb-req">*</span>@endif</span><span class="tb-blank">{{ $answerHint($item) }}</span></div>
                @else
                    @if ($item['show_label'])<div class="font-weight-bold small mt-1"><span class="tb-handle"><i class="fas fa-grip-vertical"></i></span>{{ $label }}@if ($req)<span class="tb-req">*</span>@endif</div>@endif
                    @if ($tablePreview){!! $tablePreview !!}@else<div class="text-muted font-italic small" style="border: 1px dotted #b8c4c4; border-radius: .25rem; padding: .5rem; min-height: 3.5rem;">@unless ($item['show_label'])<span class="tb-handle"><i class="fas fa-grip-vertical"></i></span>@endunless {{ $item['type'] === 'rich' ? 'Formatted text' : 'Paragraphs' }} written by the author ({{ $label }})</div>@endif
                @endif
                @break
            @case('section')
                <div class="font-weight-bold mt-2" style="color: #075B35; text-transform: uppercase;"><span class="tb-handle"><i class="fas fa-grip-vertical"></i></span>{{ $label ?: 'Section' }}</div>
                @break
            @case('note')
                <div class="small font-italic"><span class="tb-handle"><i class="fas fa-grip-vertical"></i></span>{{ $item['text'] }}</div>
                @break
            @case('signature')
                <div class="mt-3">
                    @if ($item['label'] !== '' || $isReport)<div class="font-weight-bold small">{{ rtrim($item['label'] !== '' ? $item['label'] : 'Prepared by', ': ') }}</div>@endif
                    <div class="text-muted" style="font-family: 'Brush Script MT', cursive; font-size: 1.5rem;"><span class="tb-handle" style="font-size: .875rem;"><i class="fas fa-grip-vertical"></i></span><i class="fas fa-signature"></i> signature</div>
                    <div class="font-weight-bold small">{{ $letterhead ? "SIGNATORY'S NAME" : "Signatory's name" }}</div>
                    @if (! empty($item['job_title']))<div class="small">Job title</div>@endif
                </div>
                @break
        @endswitch
    @endif
</div>
