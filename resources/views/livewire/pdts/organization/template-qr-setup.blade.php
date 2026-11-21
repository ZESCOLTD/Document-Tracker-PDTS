@php
    $teal = config('theme.color_sidebar', '#093f42');
    $viewOnly ??= false;
    $showPin ??= true;
    $config = [
        'viewOnly' => $viewOnly,
        'showPin' => $showPin,
        'kind' => $template->kind,
        'url' => $originalUrl,
        'pages' => $pages,
        'placement' => $placement,
        'min' => $limits['min'],
        'max' => $limits['max'],
        'locked' => $locked,
        'qrTyped' => $qrTyped,
        'workerSrc' => asset('vendor/pdfjs/pdf.worker.min.js'),
    ];
@endphp
<div class="container-fluid py-4">
    @assets
        <script src="{{ asset('vendor/jszip/jszip.min.js') }}"></script>
        <script src="{{ asset('vendor/docx-preview/docx-preview.min.js') }}"></script>
        <script src="{{ asset('vendor/pdfjs/pdf.min.js') }}"></script>
        <script>
            // The QR placement editor: shows the template as it is, and a QR code
            // the administrator drags to a free spot. Positions are kept in mm.
            window.pdtsQrEditor = (config) => ({
                kind: config.kind,
                pagesMm: config.pages,
                placement: { ...config.placement, x: Number(config.placement.x), y: Number(config.placement.y), size: Number(config.placement.size) },
                min: config.min,
                max: config.max,
                locked: config.locked,
                loading: true,
                failed: '',
                pageEls: [],
                pdfPageCount: config.pages.length,

                async init() {
                    try {
                        if (this.kind === 'pdf') {
                            await this.renderPdf();
                        } else {
                            await this.renderDocx();
                        }
                    } catch (e) {
                        console.error(e);
                        this.failed = 'The preview could not be shown in this browser. You can still set the position with the numbers on the right and download a sample to check it.';
                    }
                    this.loading = false;
                    this.$nextTick(() => this.drawPins());
                    // A new page choice redraws the pins; moving or resizing only
                    // repositions them, so a pin being dragged is never replaced.
                    this.$watch('placement.page', () => this.drawPins());
                    this.$watch('placement', () => this.positionPins(), { deep: true });
                    window.addEventListener('resize', () => this.drawPins());
                },

                async renderDocx() {
                    const response = await fetch(config.url, { credentials: 'same-origin' });
                    const buffer = await response.arrayBuffer();
                    const host = this.$refs.pages;
                    await window.docx.renderAsync(buffer, host, null, {
                        className: 'docx', inWrapper: true, breakPages: true, ignoreLastRenderedPageBreak: false,
                        renderHeaders: true, renderFooters: true, experimental: true, useBase64URL: true,
                    });
                    this.pageEls = [...host.querySelectorAll('section.docx')];
                    // A file without a page size is shown at Word's default (A4),
                    // so positions in mm stay true.
                    const page = this.pagesMm[0];
                    this.pageEls.forEach((el) => {
                        if (!el.style.width) el.style.width = (page.width * 96 / 25.4) + 'px';
                        if (!el.style.minHeight) el.style.minHeight = (page.height * 96 / 25.4) + 'px';
                    });
                    this.fitDocx();
                    window.addEventListener('resize', () => this.fitDocx());
                },

                fitDocx() {
                    const wrapper = this.$refs.pages.querySelector('.docx-wrapper');
                    const first = this.pageEls[0];
                    if (!wrapper || !first) return;
                    wrapper.style.zoom = 1;
                    const available = this.$refs.pages.clientWidth - 24;
                    const zoom = Math.min(1, available / (first.offsetWidth + 40));
                    wrapper.style.zoom = zoom;
                },

                async renderPdf() {
                    const lib = window.pdfjsLib;
                    lib.GlobalWorkerOptions.workerSrc = config.workerSrc;
                    const pdf = await lib.getDocument({ url: config.url, withCredentials: true }).promise;
                    this.pdfPageCount = pdf.numPages;
                    const host = this.$refs.pages;
                    const width = host.clientWidth - 32;
                    for (let n = 1; n <= pdf.numPages; n++) {
                        const page = await pdf.getPage(n);
                        const base = page.getViewport({ scale: 1 });
                        const viewport = page.getViewport({ scale: Math.min(2, width / base.width) });
                        const holder = document.createElement('div');
                        holder.className = 'qr-pdf-page';
                        holder.style.width = viewport.width + 'px';
                        holder.style.height = viewport.height + 'px';
                        const canvas = document.createElement('canvas');
                        const ratio = window.devicePixelRatio || 1;
                        canvas.width = viewport.width * ratio;
                        canvas.height = viewport.height * ratio;
                        canvas.style.width = viewport.width + 'px';
                        canvas.style.height = viewport.height + 'px';
                        holder.appendChild(canvas);
                        const label = document.createElement('div');
                        label.className = 'qr-page-no';
                        label.textContent = 'Page ' + n;
                        holder.appendChild(label);
                        host.appendChild(holder);
                        await page.render({ canvasContext: canvas.getContext('2d'), viewport, transform: ratio !== 1 ? [ratio, 0, 0, ratio, 0, 0] : null }).promise;
                        this.pageEls.push(holder);
                    }
                },

                targetIndex() {
                    const page = String(this.placement.page);
                    return /^\d+$/.test(page) ? Number(page) - 1 : 0;
                },

                pageMm(index) {
                    return this.pagesMm[Math.min(index, this.pagesMm.length - 1)] || this.pagesMm[0];
                },

                // Layout pixels per mm on a page (before any zoom).
                ppm(index) {
                    const el = this.pageEls[index];
                    return el ? el.offsetWidth / this.pageMm(index).width : 3.78;
                },

                drawPins() {
                    this.$root.querySelectorAll('.qr-pin').forEach((pin) => pin.remove());
                    if (!this.pageEls.length || !config.showPin) return;
                    const target = this.targetIndex();
                    const every = this.placement.page === 'all';
                    this.pageEls.forEach((el, i) => {
                        if (i !== target && !every) return;
                        el.appendChild(this.makePin(i, i !== target));
                    });
                },

                positionPins() {
                    this.$root.querySelectorAll('.qr-pin').forEach((pin) => this.place(pin, Number(pin.dataset.page)));
                },

                place(pin, index) {
                    const p = this.ppm(index);
                    pin.style.left = (this.placement.x * p) + 'px';
                    pin.style.top = (this.placement.y * p) + 'px';
                    pin.style.width = (this.placement.size * p) + 'px';
                    pin.style.height = (this.placement.size * p) + 'px';
                },

                makePin(index, ghost) {
                    const pin = document.createElement('div');
                    pin.className = 'qr-pin' + (ghost ? ' is-ghost' : '') + (config.viewOnly ? ' is-view' : (this.locked ? ' is-locked' : ''));
                    pin.dataset.page = index;
                    this.place(pin, index);
                    pin.innerHTML = '<img src="' + config.sampleQr + '" alt="" draggable="false">'
                        + '<span class="qr-pin-ref">OMTS-2026-0000</span>'
                        + (ghost || config.viewOnly ? '' : '<span class="qr-pin-hint">Drag me</span>');
                    if (!ghost && !this.locked) {
                        pin.addEventListener('pointerdown', (event) => this.startDrag(event, index, pin));
                    }
                    return pin;
                },

                startDrag(event, index, pin) {
                    event.preventDefault();
                    pin.setPointerCapture(event.pointerId);
                    const el = this.pageEls[index];
                    const screenScale = el.getBoundingClientRect().width / el.offsetWidth;
                    const perMm = this.ppm(index) * screenScale;
                    const start = { x: event.clientX, y: event.clientY, mmX: this.placement.x, mmY: this.placement.y };
                    const page = this.pageMm(index);
                    pin.classList.add('is-dragging');
                    const move = (e) => {
                        const x = start.mmX + (e.clientX - start.x) / perMm;
                        const y = start.mmY + (e.clientY - start.y) / perMm;
                        this.placement.x = Math.round(Math.min(Math.max(0, x), page.width - this.placement.size) * 10) / 10;
                        this.placement.y = Math.round(Math.min(Math.max(0, y), page.height - this.placement.size) * 10) / 10;
                    };
                    const up = () => {
                        pin.removeEventListener('pointermove', move);
                        pin.removeEventListener('pointerup', up);
                        pin.classList.remove('is-dragging');
                    };
                    pin.addEventListener('pointermove', move);
                    pin.addEventListener('pointerup', up);
                },

                preset(where) {
                    const page = this.pageMm(this.targetIndex());
                    const margin = 10;
                    const size = this.placement.size;
                    this.placement.x = where.includes('left') ? margin : Math.round((page.width - margin - size) * 10) / 10;
                    this.placement.y = where.includes('top') ? margin : Math.round((page.height - margin - size - (this.kind === 'docx' ? 4 : 0)) * 10) / 10;
                },

                clamp() {
                    const page = this.pageMm(this.targetIndex());
                    this.placement.size = Math.min(this.max, Math.max(this.min, Number(this.placement.size) || this.min));
                    this.placement.x = Math.min(Math.max(0, Number(this.placement.x) || 0), page.width - this.placement.size);
                    this.placement.y = Math.min(Math.max(0, Number(this.placement.y) || 0), page.height - this.placement.size);
                },
            });
        </script>
    @endassets

    <style>
        .qr-setup-preview { background: #e9eef0; border-radius: .75rem; min-height: 60vh; max-height: calc(100vh - 11rem); overflow: auto; padding: 1rem; position: relative; }
        .qr-setup-preview .docx-wrapper { background: transparent !important; padding: 0 !important; }
        .qr-setup-preview section.docx { position: relative; box-shadow: 0 2px 10px rgba(0, 0, 0, .15) !important; margin: 0 auto 1rem !important; }
        .qr-pdf-page { position: relative; margin: 0 auto 1rem; background: #fff; box-shadow: 0 2px 10px rgba(0, 0, 0, .15); }
        .qr-page-no { position: absolute; left: .5rem; bottom: -1.3rem; font-size: .75rem; color: #6c7a7a; }
        .qr-pin { position: absolute; z-index: 20; cursor: grab; outline: 2px solid {{ $teal }}; outline-offset: 2px; border-radius: 2px; background: #fff; touch-action: none; box-shadow: 0 0 0 4px rgba(9, 63, 66, .15); }
        .qr-pin img { width: 100%; height: 100%; display: block; image-rendering: pixelated; pointer-events: none; }
        .qr-pin.is-dragging { cursor: grabbing; box-shadow: 0 0 0 6px rgba(9, 63, 66, .25); }
        .qr-pin.is-ghost { opacity: .45; outline-style: dashed; cursor: default; pointer-events: none; }
        .qr-pin.is-locked { cursor: not-allowed; }
        /* One line, like the printed reference; docx-preview's ".docx span" would otherwise wrap it at the hyphens. */
        .qr-pin .qr-pin-ref { position: absolute; top: 100%; left: 50%; transform: translateX(-50%); width: max-content; white-space: nowrap !important; overflow-wrap: normal !important; word-break: keep-all; font: 600 7px/1.4 Arial, sans-serif; color: #111; background: rgba(255, 255, 255, .85); padding: 0 2px; }
        .qr-pin-hint { position: absolute; bottom: 100%; left: 50%; transform: translate(-50%, -6px); white-space: nowrap; font-size: .6875rem; font-weight: 600; color: #fff; background: {{ $teal }}; border-radius: 999px; padding: .1rem .5rem; pointer-events: none; }
        .qr-pin.is-locked .qr-pin-hint { display: none; }
        .qr-pin.is-view { cursor: default; outline: none; box-shadow: none; }
        .qr-pin.is-view.is-ghost { opacity: 1; }
        .qr-facts dt { font-size: .75rem; font-weight: 600; color: #6c7a7a; margin-bottom: .1rem; }
        .qr-facts dd { margin-bottom: .75rem; }
        .qr-side { position: sticky; top: 1rem; }
        .qr-side .card { border-radius: .75rem; }
        .qr-step { font-size: .6875rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #6c7a7a; margin-bottom: .5rem; }
        .qr-seg { display: flex; flex-wrap: wrap; gap: .375rem; }
        .qr-seg label { flex: 1 1 auto; margin: 0; border: 1.5px solid #dfe6e6; border-radius: .5rem; padding: .375rem .625rem; font-size: .8125rem; font-weight: 600; text-align: center; cursor: pointer; }
        .qr-seg label.is-on { border-color: {{ $teal }}; background: #eef5f5; color: {{ $teal }}; }
        .qr-seg input { position: absolute; opacity: 0; pointer-events: none; }
        .qr-presets { display: grid; grid-template-columns: 1fr 1fr; gap: .375rem; }
        .qr-presets button { font-size: .8125rem; }
        .qr-chip { display: inline-flex; align-items: center; gap: .3rem; padding: .15rem .55rem; border-radius: 999px; background: #f1f8f5; border: 1px solid #cfe4d9; font-size: .75rem; font-weight: 600; color: #1d5c3f; margin: 0 .25rem .25rem 0; }
        .qr-range { width: 100%; accent-color: {{ $teal }}; }
    </style>

    <div class="d-flex flex-wrap justify-content-between align-items-start mb-3" style="gap: .5rem;">
        <div>
            <a href="{{ route('pdts.organization.document-templates') }}" class="small text-muted"><i class="fas fa-arrow-left mr-1"></i>Document Templates</a>
            <h1 class="h3 mb-1 mt-1">{{ $viewOnly ? $template->name : 'Place the QR code' }}</h1>
            <p class="text-muted mb-0">
                <i class="fas {{ $template->isPdf() ? 'fa-file-pdf text-danger' : 'fa-file-word text-primary' }} mr-1"></i>@unless ($viewOnly){{ $template->name }}<span class="mx-1">·</span>@endunless{{ $template->original_filename }}
                @if ($template->description)<span class="mx-1">·</span>{{ $template->description }}@endif
            </p>
        </div>
        @if ($viewOnly && ! $problem)
            <div class="d-flex flex-wrap" style="gap: .5rem;">
                <button type="button" class="btn btn-outline-secondary" wire:click="downloadSavedSample" wire:loading.attr="disabled" wire:target="downloadSavedSample" @disabled(! $showPin)>
                    <span wire:loading.remove wire:target="downloadSavedSample"><i class="fas fa-download mr-1"></i>Download sample</span>
                    <span wire:loading wire:target="downloadSavedSample"><i class="fas fa-circle-notch fa-spin mr-1"></i>Preparing…</span>
                </button>
                @unless ($template->documents_count)
                    <a href="{{ route('pdts.organization.document-templates.qr', $template) }}" class="btn btn-primary"><i class="fas fa-qrcode mr-1"></i>{{ $template->isConfigured() ? 'Move QR code' : 'Place QR code' }}</a>
                @endunless
            </div>
        @endif
    </div>

    @if (session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif

    @if ($problem)
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle mr-1"></i>{{ $problem }}
            <a href="{{ route('pdts.organization.document-templates') }}" class="alert-link ml-1">Back to templates</a>
        </div>
    @else
        <div class="row" x-data="pdtsQrEditor(@js($config + ['sampleQr' => $sampleQr]))">
            <div class="col-lg-8 mb-3">
                <div class="qr-setup-preview" x-ref="previewBox">
                    <div x-show="loading" class="text-center text-muted py-5"><i class="fas fa-circle-notch fa-spin fa-2x mb-2 d-block"></i>Opening the template…</div>
                    <div x-show="failed" x-cloak class="alert alert-warning m-3" x-text="failed"></div>
                    <div x-ref="pages" wire:ignore></div>
                </div>
                <div class="small text-muted mt-2"><i class="fas fa-info-circle mr-1"></i>
                    @if ($viewOnly)
                        The template as uploaded, with a sample QR code and reference where each document's own will print.
                    @else
                        This is the template exactly as uploaded. Drag the QR code to an empty spot so it does not cover text, logos or signatures.
                    @endif
                </div>
            </div>

            <div class="col-lg-4">
                @if ($viewOnly)
                    <div class="qr-side">
                        <div class="card shadow-sm border-0">
                            <div class="card-body">
                                <dl class="qr-facts mb-0">
                                    <dt>Status</dt>
                                    <dd>
                                        @if (! $template->isConfigured())
                                            <span class="badge badge-warning">Needs QR code</span>
                                        @elseif ($template->is_active)
                                            <span class="badge badge-success">Active</span>
                                        @else
                                            <span class="badge badge-secondary">Inactive</span>
                                        @endif
                                    </dd>
                                    <dt>Document type</dt>
                                    <dd>{{ $template->documentType?->name ?? 'Any (the author chooses)' }}</dd>
                                    <dt>QR code</dt>
                                    <dd><i class="fas fa-qrcode text-muted mr-1"></i>{{ $template->qrLabel() }}</dd>
                                    <dt>Form asks for</dt>
                                    <dd>
                                        <span class="qr-chip"><i class="fas fa-check"></i>Subject</span>
                                        @foreach ($formFields as $label)
                                            @continue($label === 'Subject')
                                            <span class="qr-chip"><i class="fas fa-check"></i>{{ $label }}</span>
                                        @endforeach
                                        @if ($template->isPdf())
                                            <div class="small text-muted mt-1">Details are written on the paper by hand.</div>
                                        @endif
                                    </dd>
                                    <dt>Documents made from it</dt>
                                    <dd>{{ $template->documents_count }}@if ($template->documents_count) <span class="small text-muted">(QR code position is fixed)</span>@endif</dd>
                                    <dt>Uploaded</dt>
                                    <dd class="mb-0">{{ $template->uploader?->name ?? 'Unknown' }}, {{ $template->created_at?->format('j M Y') }}</dd>
                                </dl>
                            </div>
                        </div>
                    </div>
                @else
                <div class="qr-side">
                    @if ($locked)
                        <div class="alert alert-warning small"><i class="fas fa-lock mr-1"></i>Documents were made from this template, so its QR code can no longer be moved.</div>
                    @elseif ($qrTyped)
                        <div class="alert alert-info small"><i class="fas fa-info-circle mr-1"></i>This file already had a QR code spot typed in it. Drag the QR code where you want it; when you save, the old spot is removed.</div>
                    @endif

                    <div class="card shadow-sm border-0 mb-3">
                        <div class="card-body">

                                <div class="qr-step">1 · Page</div>
                                <div class="qr-seg mb-2" role="radiogroup" aria-label="Page">
                                    <label :class="{ 'is-on': placement.page === 'all' }"><input type="radio" value="all" x-model="placement.page" :disabled="locked">Every page</label>
                                    <label :class="{ 'is-on': placement.page === 'first' }"><input type="radio" value="first" x-model="placement.page" :disabled="locked">First page only</label>
                                </div>
                                @if ($template->isPdf())
                                    <div class="d-flex align-items-center mb-3" style="gap: .5rem;">
                                        <span class="small text-muted">or one page:</span>
                                        <select class="form-control form-control-sm" style="max-width: 8rem;" :disabled="locked"
                                                x-on:change="placement.page = $event.target.value; clamp()"
                                                :value="/^\d+$/.test(placement.page) ? placement.page : ''">
                                            <option value="">Choose…</option>
                                            <template x-for="n in pdfPageCount" :key="n"><option :value="String(n)" x-text="'Page ' + n"></option></template>
                                        </select>
                                    </div>
                                @else
                                    <div class="small text-muted mb-3">Word documents grow as they are typed, so the QR code goes on every page or on the first page.</div>
                                @endif

                                <div class="qr-step">2 · Position <span class="text-lowercase font-weight-normal">(or drag it on the preview)</span></div>
                                <div class="qr-presets mb-2">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" x-on:click="preset('top-left')" :disabled="locked"><i class="fas fa-arrow-up mr-1" style="transform: rotate(-45deg);"></i>Top left</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" x-on:click="preset('top-right')" :disabled="locked"><i class="fas fa-arrow-up mr-1" style="transform: rotate(45deg);"></i>Top right</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" x-on:click="preset('bottom-left')" :disabled="locked"><i class="fas fa-arrow-down mr-1" style="transform: rotate(45deg);"></i>Bottom left</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" x-on:click="preset('bottom-right')" :disabled="locked"><i class="fas fa-arrow-down mr-1" style="transform: rotate(-45deg);"></i>Bottom right</button>
                                </div>
                                <div class="form-row mb-3">
                                    <div class="col">
                                        <label class="small text-muted mb-0" for="qr-x">From left (mm)</label>
                                        <input id="qr-x" type="number" step="0.5" min="0" class="form-control form-control-sm" x-model.number="placement.x" x-on:change="clamp()" :disabled="locked">
                                    </div>
                                    <div class="col">
                                        <label class="small text-muted mb-0" for="qr-y">From top (mm)</label>
                                        <input id="qr-y" type="number" step="0.5" min="0" class="form-control form-control-sm" x-model.number="placement.y" x-on:change="clamp()" :disabled="locked">
                                    </div>
                                </div>


                            <div class="qr-step">3 · Size</div>
                            <div class="d-flex align-items-center mb-1" style="gap: .75rem;">
                                <input type="range" class="qr-range" :min="min" :max="max" step="1" x-model.number="placement.size" x-on:input="clamp()" :disabled="{{ $locked ? 'true' : 'false' }}" aria-label="QR code size">
                                <span class="font-weight-bold text-nowrap" x-text="placement.size + ' mm'"></span>
                            </div>
                            <div class="small text-muted">At least 20 mm scans easily from a phone.</div>
                        </div>
                    </div>

                    @if (! $template->isPdf())
                        <div class="card shadow-sm border-0 mb-3">
                            <div class="card-body">
                                <div class="qr-step">PDTS will fill in</div>
                                @forelse ($fields as $field)
                                    <span class="qr-chip"><i class="fas fa-check"></i>{{ ['to' => 'To', 'from' => 'From', 'cc' => 'CC', 'office_reference' => 'Ref', 'date' => 'Date', 'subject' => 'Subject'][$field] ?? $field }}</span>
                                @empty
                                    <div class="small text-muted">No TO, FROM, CC, REF, DATE or SUBJECT lines with space to fill were found. The QR code is still added.</div>
                                @endforelse
                            </div>
                        </div>
                    @else
                        <div class="small text-muted mb-3"><i class="fas fa-info-circle mr-1"></i>PDF templates are printed as they are, with each document's QR code added. Details are written on the paper by hand.</div>
                    @endif

                    @error('placement') <div class="alert alert-danger small">{{ $message }}</div> @enderror

                    <div class="d-flex flex-column" style="gap: .5rem;">
                        <button type="button" class="btn btn-outline-secondary" x-on:click="$wire.downloadSample(placement)" wire:loading.attr="disabled" wire:target="downloadSample">
                            <span wire:loading.remove wire:target="downloadSample"><i class="fas fa-eye mr-1"></i>Download a sample to check</span>
                            <span wire:loading wire:target="downloadSample"><i class="fas fa-circle-notch fa-spin mr-1"></i>Preparing sample…</span>
                        </button>
                        @unless ($locked)
                            <button type="button" class="btn btn-primary btn-lg" x-on:click="$wire.save(placement)" wire:loading.attr="disabled" wire:target="save">
                                <span wire:loading.remove wire:target="save"><i class="fas fa-check mr-1"></i>Save template</span>
                                <span wire:loading wire:target="save"><i class="fas fa-circle-notch fa-spin mr-1"></i>Saving…</span>
                            </button>
                        @endunless
                    </div>
                </div>
                @endif
            </div>
        </div>
    @endif
</div>
