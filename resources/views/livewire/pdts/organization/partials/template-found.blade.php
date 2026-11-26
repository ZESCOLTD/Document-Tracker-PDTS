{{--
    What PDTS found in an uploaded template: the fields, the spaces to write in,
    and how it is signed. On the template's Form settings page and on the QR
    code page after uploading.

    $check: TemplateSetup::check(); $template; $stacked: one group under the
    other (a narrow column); $tracking: show the QR code and subject group.
--}}
@php($stacked = $stacked ?? false)
@php($tracking = $tracking ?? true)
<style>
    .tf-found { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: .75rem; }
    .tf-item { border: 1px solid #e3e9e8; border-radius: .5rem; padding: .75rem 1rem; background: #fff; }
    /* In a narrow column: one group under the other, no boxes, smaller, so it does not crowd out the rest. */
    .tf-found.is-stacked { grid-template-columns: 1fr; gap: .5rem; }
    .tf-found.is-stacked .tf-item { border: 0; padding: 0; }
    .tf-found.is-stacked .tf-head { font-size: .6875rem; margin-bottom: .125rem; }
    .tf-found.is-stacked .tf-chip { font-size: .6875rem; padding: 0 .4rem; margin: 0 .2rem .2rem 0; }
    .tf-found.is-stacked .small { font-size: .75rem; }
    .tf-head { font-size: .75rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: #6c7a7a; margin-bottom: .25rem; }
    .tf-chip { display: inline-block; font-size: .75rem; padding: .1rem .5rem; border-radius: 999px; background: #eef5f5; margin: 0 .25rem .25rem 0; }
    .tf-ok { color: #1e7b3c; } .tf-no { color: #b4332d; }
</style>
<div class="tf-found {{ $stacked ? 'is-stacked' : '' }}" data-test="template-found">
    <div class="tf-item">
        <div class="tf-head">Fields to fill</div>
        @forelse (collect($check['fields'])->whereIn('kind', ['heading', 'field']) as $field)
            <span class="tf-chip">{{ $field['label'] }}</span>
        @empty
            <span class="small text-muted">None</span>
        @endforelse
    </div>
    <div class="tf-item">
        <div class="tf-head">Spaces to write in</div>
        @forelse (collect($check['fields'])->where('kind', 'writing') as $field)
            <span class="tf-chip">{{ $field['label'] }}</span>
        @empty
            <span class="small text-muted">None</span>
        @endforelse
    </div>
    <div class="tf-item">
        <div class="tf-head">Signing</div>
        @if ($check['signature'])
            <div class="tf-ok small"><i class="fas fa-check-circle mr-1"></i>Signature spot found: can be signed in PDTS</div>
        @elseif ($template->isUnsigned())
            <div class="tf-ok small"><i class="fas fa-check-circle mr-1"></i>Not signed: issued in PDTS without a signature</div>
        @elseif ($template->isPdf())
            <div class="text-muted small"><i class="fas fa-minus-circle mr-1"></i>PDF: filled in and signed by hand</div>
        @else
            <div class="tf-no small"><i class="fas fa-times-circle mr-1"></i>No signature spot: not offered to authors yet</div>
        @endif
        <div class="small mt-1 {{ $check['authorLine'] ? 'tf-ok' : 'text-muted' }}"><i class="fas {{ $check['authorLine'] ? 'fa-check-circle' : 'fa-minus-circle' }} mr-1"></i>{{ $check['authorLine'] ? 'Name line filled with the signatory\'s name' : 'No name line' }}</div>
    </div>
    @if ($tracking)
        <div class="tf-item">
            <div class="tf-head">Tracking</div>
            <div class="small {{ $check['qr'] ? 'tf-ok' : 'tf-no' }}"><i class="fas {{ $check['qr'] ? 'fa-check-circle' : 'fa-times-circle' }} mr-1"></i>{{ $check['qr'] ? 'QR code and reference placed' : 'QR code not placed yet' }}</div>
            <div class="small mt-1 {{ $check['subject'] ? 'tf-ok' : 'text-muted' }}"><i class="fas {{ $check['subject'] ? 'fa-check-circle' : 'fa-minus-circle' }} mr-1"></i>{{ $check['subject'] ? 'Subject printed on the document' : 'Subject kept for tracking only' }}</div>
        </div>
    @endif
</div>
