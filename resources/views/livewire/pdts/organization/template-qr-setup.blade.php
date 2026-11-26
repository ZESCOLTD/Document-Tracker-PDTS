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
        'marks' => $marks ?? [],
    ];
@endphp
<div class="container-fluid pt-2 pb-3">
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
                // How the template is shown: the whole page in view, or as wide as the preview (bigger; scrolls down).
                fitMode: (() => { try { return localStorage.getItem('pdts.qr.view') || 'width'; } catch (e) { return 'width'; } })(),
                // Zoom on top of the fit, from the - and + buttons (1 = as fitted).
                extraZoom: 1,
                shownZoom: 1,
                pdfPageCount: config.pages.length,
                // Lines marked by hand: { line, text, kind: field|writing|signature, label }
                marks: (config.marks || []).map((m) => ({ ...m })),
                marking: false,
                picked: null, // { line, text } chosen in the preview, waiting for a kind and name
                pickKind: 'field',
                pickLabel: '',

                lines() {
                    return [...this.$refs.pages.querySelectorAll('section.docx > article p')];
                },

                startMarking() {
                    this.marking = ! this.marking;
                    this.picked = null;
                    this.$refs.pages.classList.toggle('is-marking', this.marking);
                },

                pickLine(event) {
                    if (! this.marking) return;
                    const p = event.target.closest('section.docx > article p');
                    if (! p) return;
                    event.preventDefault();
                    const line = this.lines().indexOf(p);
                    this.picked = { line, text: p.textContent.trim().slice(0, 300) };
                    const existing = this.marks.find((m) => m.line === line);
                    this.pickKind = existing ? existing.kind : (p.textContent.trim() === '' ? 'writing' : 'field');
                    this.pickLabel = existing ? existing.label : '';
                },

                addMark() {
                    if (! this.picked) return;
                    if (this.pickKind !== 'signature' && this.pickLabel.trim() === '') return;
                    if (this.pickKind === 'signature') {
                        this.marks = this.marks.filter((m) => m.kind !== 'signature');
                    }
                    this.marks = this.marks.filter((m) => m.line !== this.picked.line);
                    this.marks.push({ line: this.picked.line, text: this.picked.text, kind: this.pickKind, label: this.pickKind === 'signature' ? '' : this.pickLabel.trim() });
                    this.marks.sort((a, b) => a.line - b.line);
                    this.picked = null;
                    this.showMarks();
                },

                removeMark(line) {
                    this.marks = this.marks.filter((m) => m.line !== line);
                    this.showMarks();
                },

                showMarks() {
                    if (this.kind !== 'docx') return;
                    const lines = this.lines();
                    lines.forEach((p) => { p.classList.remove('qr-marked'); delete p.dataset.mark; });
                    this.marks.forEach((m) => {
                        const p = lines[m.line];
                        if (! p) return;
                        p.classList.add('qr-marked');
                        p.dataset.mark = m.kind === 'signature' ? 'Signature spot' : (m.kind === 'writing' ? 'Writing space: ' : 'Field: ') + (m.label || '');
                    });
                },

                markKindLabel(kind) {
                    return { field: 'Field', writing: 'Writing space', signature: 'Signature spot' }[kind] || kind;
                },

                // The preview and the side panel fill the screen below the page heading, so Save is always in view.
                fitHeight() {
                    const top = this.$root.getBoundingClientRect().top + window.scrollY;
                    const page = document.documentElement;
                    let height = Math.max(420, window.innerHeight - top - 16);
                    this.$root.style.setProperty('--qr-h', height + 'px');
                    // Whatever still makes the window scroll (the page's own padding, a footer) comes off too.
                    const extra = page.scrollHeight - window.innerHeight;
                    if (extra > 0) {
                        height = Math.max(420, height - extra);
                        this.$root.style.setProperty('--qr-h', height + 'px');
                    }
                },

                async init() {
                    this.fitHeight();
                    window.addEventListener('resize', () => this.fitHeight());
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
                    this.fit();
                    window.addEventListener('resize', () => this.fit());
                    this.$nextTick(() => { this.drawPins(); this.showMarks(); });
                    this.$refs.pages.addEventListener('click', (event) => this.pickLine(event));
                    // A new page choice redraws the pins; moving or resizing only
                    // repositions them, so a pin being dragged is never replaced.
                    this.$watch('placement.page', () => this.drawPins());
                    this.$watch('placement', () => this.positionPins(), { deep: true });
                    window.addEventListener('resize', () => this.drawPins());
                },

                // A tab typed as a character inside the text (as in ZESCO's memo,
                // "TO: …<tab>FROM: …") is drawn by the previewer as a space, so FROM
                // would sit right after TO. Word prints it at the tab stop: make it a
                // real tab in this preview copy (the file itself is not changed).
                async withRealTabs(buffer) {
                    try {
                        const zip = await window.JSZip.loadAsync(buffer);
                        let changed = false;
                        for (const name of Object.keys(zip.files).filter((n) => /^word\/(document|header\d*|footer\d*)\.xml$/.test(n))) {
                            const xml = await zip.file(name).async('string');
                            const fixed = xml.replace(/<w:t(\s[^>]*)?>([^<]*(?:\t|&#9;|&#x9;)[^<]*)<\/w:t>/g, (all, attrs, text) =>
                                text.split(/\t|&#9;|&#x9;/).map((part) => '<w:t xml:space="preserve">' + part + '</w:t>').join('<w:tab/>'));
                            if (fixed !== xml) { zip.file(name, fixed); changed = true; }
                        }
                        return changed ? await zip.generateAsync({ type: 'arraybuffer' }) : buffer;
                    } catch (e) {
                        return buffer;
                    }
                },

                async renderDocx() {
                    const response = await fetch(config.url, { credentials: 'same-origin' });
                    const buffer = await this.withRealTabs(await response.arrayBuffer());
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
                },

                /*
                 * Scales the template in the preview: "page" shows the whole first page,
                 * "width" makes it as wide as the preview. Pins follow, as they sit on the pages.
                 */
                fit() {
                    const first = this.pageEls[0];
                    const target = this.kind === 'pdf' ? this.$refs.pages : this.$refs.pages.querySelector('.docx-wrapper');
                    if (!first || !target) return;
                    target.style.zoom = 1;
                    const box = this.$refs.previewBox;
                    const byWidth = (box.clientWidth - 24) / first.offsetWidth;
                    const byHeight = (box.clientHeight - 24) / (first.offsetHeight + (this.kind === 'pdf' ? 20 : 4));
                    const zoom = Math.min(2.5, (this.fitMode === 'width' ? byWidth : Math.min(byWidth, byHeight)) * this.extraZoom);
                    target.style.zoom = zoom;
                    this.shownZoom = zoom;
                },

                zoomStep(step) {
                    this.extraZoom = Math.max(0.5, Math.min(2.5, Math.round((this.extraZoom + step) * 10) / 10));
                    this.fit();
                },

                setFit(mode) {
                    this.fitMode = mode;
                    try { localStorage.setItem('pdts.qr.view', mode); } catch (e) {}
                    this.extraZoom = 1;
                    this.fit();
                    this.$refs.previewBox.scrollTop = 0;
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
                    // Near the top of the page the "Drag me" label would be cut off above it: show it below instead.
                    pin.classList.toggle('hint-below', this.placement.y * p < 48);
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
        .qr-setup-preview { background: #e9eef0; border-radius: .75rem; height: calc(var(--qr-h, calc(100vh - 8rem)) - 2.25rem); min-height: 340px; overflow: auto; padding: .5rem; position: relative; }
        .qr-fit { display: flex; justify-content: flex-end; gap: .5rem; height: 2.25rem; align-items: center; }
        .qr-fit > div { display: inline-flex; background: #fff; border: 1px solid #dfe6e6; border-radius: .5rem; overflow: hidden; box-shadow: 0 1px 4px rgba(0, 0, 0, .08); }
        .qr-fit button { border: 0; background: #fff; font-size: .75rem; padding: .25rem .6rem; color: #3d4b4b; }
        .qr-fit button + button { border-left: 1px solid #dfe6e6; }
        .qr-fit button.is-on { background: {{ $teal }}; color: #fff; }
        .qr-fit-pct { font-size: .72rem; color: #3d4b4b; min-width: 2.75rem; text-align: center; align-self: center; border-left: 1px solid #dfe6e6; border-right: 1px solid #dfe6e6; padding: .25rem .25rem; }
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
        .qr-setup-preview .qr-pin .qr-pin-hint { white-space: nowrap !important; word-break: keep-all; line-height: 1.4; }
        .qr-pin.hint-below .qr-pin-hint { bottom: auto; top: 100%; transform: translate(-50%, 16px); }
        .qr-pin.is-view { cursor: default; outline: none; box-shadow: none; }
        .qr-pin.is-view.is-ghost { opacity: 1; }
        .qr-facts dt { font-size: .75rem; font-weight: 600; color: #6c7a7a; margin-bottom: .1rem; }
        .qr-facts dd { margin-bottom: .75rem; }
        /* As tall as the preview, scrolling on its own; it ends above the support chat button (fixed at the bottom right). */
        .qr-side { position: sticky; top: 1rem; max-height: calc(var(--qr-h, calc(100vh - 8rem)) - 4rem); overflow-y: auto; overscroll-behavior: contain; scrollbar-width: thin; padding-right: 2px; }
        .qr-side .card-body { padding: .875rem 1rem; }
        .qr-side .card { margin-bottom: .625rem !important; }
        @media (max-width: 991.98px) { .qr-side { position: static; max-height: none; overflow: visible; } .qr-setup-preview { height: 70vh; } }
        .qr-side .card { border-radius: .75rem; }
        .qr-step { font-size: .6875rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #6c7a7a; margin-bottom: .375rem; }
        .qr-seg { display: flex; flex-wrap: wrap; gap: .375rem; }
        .qr-seg label { flex: 1 1 auto; margin: 0; border: 1.5px solid #dfe6e6; border-radius: .5rem; padding: .25rem .5rem; font-size: .78rem; font-weight: 600; text-align: center; cursor: pointer; }
        .qr-seg label.is-on { border-color: {{ $teal }}; background: #eef5f5; color: {{ $teal }}; }
        .qr-seg input { position: absolute; opacity: 0; pointer-events: none; }
        .qr-presets { display: grid; grid-template-columns: repeat(4, 1fr); gap: .375rem; }
        .qr-presets button { font-size: .8125rem; }
        .qr-chip { display: inline-flex; align-items: center; gap: .3rem; padding: .15rem .55rem; border-radius: 999px; background: #f1f8f5; border: 1px solid #cfe4d9; font-size: .75rem; font-weight: 600; color: #1d5c3f; margin: 0 .25rem .25rem 0; }
        .qr-range { width: 100%; accent-color: {{ $teal }}; }
        .qr-setup-preview .is-marking section.docx > article p { cursor: pointer; }
        .qr-setup-preview .is-marking section.docx > article p:hover { background: rgba(7, 91, 53, .08); outline: 1px dashed #075B35; }
        .qr-setup-preview p.qr-marked { background: rgba(232, 137, 12, .12); outline: 2px solid #e8890c; position: relative; }
        .qr-setup-preview p.qr-marked::after { content: attr(data-mark); position: absolute; right: 0; top: -1.3em; font: 600 10px/1.4 Arial, sans-serif; color: #fff; background: #e8890c; padding: 0 6px; border-radius: 3px; white-space: nowrap; }
        .qr-mark-row { display: flex; justify-content: space-between; align-items: center; gap: .5rem; font-size: .8125rem; padding: .25rem 0; border-bottom: 1px solid #eef0f3; }
    </style>

    {{-- The heading and the buttons on one row, so the preview and the settings get the rest of the window. --}}
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 qr-head" style="gap: .5rem;">
        <div class="d-flex align-items-center" style="gap: .75rem; min-width: 0;">
            <a href="{{ route('pdts.organization.document-templates') }}" class="text-muted" title="Back to Document Templates" aria-label="Back to Document Templates"><i class="fas fa-arrow-left"></i></a>
            <div style="min-width: 0;">
                <h1 class="h5 mb-0">{{ $viewOnly ? $template->name : 'Place the QR code' }}</h1>
                <div class="small text-muted text-truncate" title="{{ $template->description }}">
                    <i class="fas {{ $template->isPdf() ? 'fa-file-pdf text-danger' : 'fa-file-word text-primary' }} mr-1"></i>@unless ($viewOnly){{ $template->name }}<span class="mx-1">·</span>@endunless{{ $template->original_filename }}
                </div>
            </div>
        </div>
        @unless ($viewOnly || $problem)
            {{-- The editor below holds the placement and the marks; these buttons ask it to act. --}}
            <div class="d-flex flex-wrap" style="gap: .5rem;">
                <button type="button" class="btn btn-sm btn-outline-secondary bg-white" x-data x-on:click="$dispatch('qr-sample')" wire:loading.attr="disabled" wire:target="downloadSample" data-test="qr-sample">
                    <span wire:loading.remove wire:target="downloadSample"><i class="fas fa-eye mr-1"></i>Download a sample to check</span>
                    <span wire:loading wire:target="downloadSample"><i class="fas fa-circle-notch fa-spin mr-1"></i>Preparing sample…</span>
                </button>
                @unless ($locked)
                    <button type="button" class="btn btn-sm btn-primary" x-data x-on:click="$dispatch('qr-save')" wire:loading.attr="disabled" wire:target="save" data-test="qr-save">
                        <span wire:loading.remove wire:target="save"><i class="fas fa-check mr-1"></i>Save template</span>
                        <span wire:loading wire:target="save"><i class="fas fa-circle-notch fa-spin mr-1"></i>Saving…</span>
                    </button>
                @endunless
            </div>
        @endunless
    </div>

    @if (session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif

    @if ($problem)
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle mr-1"></i>{{ $problem }}
            <a href="{{ route('pdts.organization.document-templates') }}" class="alert-link ml-1">Back to templates</a>
        </div>
    @else
        <div class="row" x-data="pdtsQrEditor(@js($config + ['sampleQr' => $sampleQr]))"
            x-on:qr-save.window="$wire.save(placement, marks)" x-on:qr-sample.window="$wire.downloadSample(placement, marks)">
            <div class="col-lg-8 col-xl-9 mb-3">
                {{-- The view controls in a bar of their own above the preview, always in view. --}}
                <div class="qr-fit" data-test="qr-fit">
                    <div role="group" aria-label="How big the template is shown">
                        <button type="button" :class="{ 'is-on': fitMode === 'page' }" x-on:click="setFit('page')" title="The whole page in view"><i class="fas fa-compress mr-1"></i>Whole page</button>
                        <button type="button" :class="{ 'is-on': fitMode === 'width' }" x-on:click="setFit('width')" title="As wide as the preview: bigger, scroll down for the rest"><i class="fas fa-arrows-alt-h mr-1"></i>Page width</button>
                    </div>
                    <div role="group" aria-label="Zoom">
                        <button type="button" x-on:click="zoomStep(-0.1)" title="Zoom out (or Ctrl + mouse wheel)" aria-label="Zoom out"><i class="fas fa-search-minus"></i></button>
                        <span class="qr-fit-pct" x-text="Math.round(shownZoom * 100) + '%'">100%</span>
                        <button type="button" x-on:click="zoomStep(0.1)" title="Zoom in (or Ctrl + mouse wheel)" aria-label="Zoom in"><i class="fas fa-search-plus"></i></button>
                    </div>
                </div>
                {{-- Ctrl + mouse wheel zooms the template, as in Word (instead of the whole browser page). --}}
                <div class="qr-setup-preview" x-ref="previewBox" x-on:wheel="if ($event.ctrlKey) { $event.preventDefault(); zoomStep($event.deltaY < 0 ? 0.1 : -0.1); }">
                    <div x-show="loading" class="text-center text-muted py-5"><i class="fas fa-circle-notch fa-spin fa-2x mb-2 d-block"></i>Opening the template…</div>
                    <div x-show="failed" x-cloak class="alert alert-warning m-3" x-text="failed"></div>
                    <div x-ref="pages" wire:ignore></div>
                </div>
            </div>

            <div class="col-lg-4 col-xl-3">
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
                                    <dd>{{ $template->documentType?->name ?? 'Any' }}</dd>
                                    <dt>Signed</dt>
                                    <dd>{{ $template->supportsElectronicSignature() ? 'In PDTS or on paper' : ($template->isUnsigned() ? 'Not signed' : 'On paper') }}</dd>
                                    <dt>Documents made from it</dt>
                                    <dd>{{ $template->documents_count }}@if ($template->documents_count)<span class="small text-muted ml-1" title="The QR code stays where it printed on them. A new version can move it." data-test="qr-fixed"><i class="fas fa-lock mx-1"></i>QR code fixed</span>@endif</dd>
                                    <dt>{{ $template->isBuilt() ? 'Built' : 'Uploaded' }}</dt>
                                    <dd class="mb-0">{{ $template->uploader?->name ?? 'Unknown' }}, {{ $template->created_at?->format('j M Y') }}</dd>
                                </dl>
                            </div>
                        </div>
                        @if ($found)
                            <div class="card shadow-sm border-0 mt-3" data-test="qr-found">
                                <div class="card-body">
                                    <div class="qr-step">What PDTS found</div>
                                    @include('livewire.pdts.organization.partials.template-found', ['check' => $found, 'template' => $template, 'stacked' => true, 'tracking' => false])
                                </div>
                            </div>
                        @endif
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

                                <div class="qr-step" @unless ($template->isPdf()) title="Word documents grow as they are typed, so the QR code goes on every page or on the first page." @endunless>1 · Page</div>
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
                                @endif

                                <div class="qr-step" title="Drag the QR code to an empty spot on the preview so it does not cover text, logos or signatures, or pick a corner.">2 · Position <span class="text-lowercase font-weight-normal">(or drag it on the preview)</span></div>
                                {{-- The four corners on one row; the name shows on hover. --}}
                                <div class="qr-presets mb-2">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" x-on:click="preset('top-left')" :disabled="locked" title="Top left" aria-label="Top left"><i class="fas fa-arrow-up" style="transform: rotate(-45deg);"></i></button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" x-on:click="preset('top-right')" :disabled="locked" title="Top right" aria-label="Top right"><i class="fas fa-arrow-up" style="transform: rotate(45deg);"></i></button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" x-on:click="preset('bottom-left')" :disabled="locked" title="Bottom left" aria-label="Bottom left"><i class="fas fa-arrow-down" style="transform: rotate(45deg);"></i></button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" x-on:click="preset('bottom-right')" :disabled="locked" title="Bottom right" aria-label="Bottom right"><i class="fas fa-arrow-down" style="transform: rotate(-45deg);"></i></button>
                                </div>
                                <div class="form-row mb-2">
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

                    @if ($found)
                        <div class="card shadow-sm border-0 mb-3" data-test="qr-found">
                            <div class="card-body">
                                <div class="qr-step">What PDTS found</div>
                                @include('livewire.pdts.organization.partials.template-found', ['check' => $found, 'template' => $template, 'stacked' => true, 'tracking' => false])
                                @unless ($locked)<div class="small text-muted mt-2">Something missing? Mark it below.</div>@endunless
                            </div>
                        </div>
                    @endif

                    @if (! $template->isPdf() && ! $locked)
                        <div class="card shadow-sm border-0 mb-3" data-test="mark-lines">
                            <div class="card-body">
                                <div class="qr-step">Fix what PDTS missed</div>
                                <div class="small text-muted mb-2">A line that should be filled in, written in or signed but was not found? Mark it on the preview.</div>
                                <button type="button" class="btn btn-sm mb-2" :class="marking ? 'btn-warning' : 'btn-outline-secondary'" x-on:click="startMarking()">
                                    <i class="fas fa-highlighter mr-1"></i><span x-text="marking ? 'Done marking' : 'Mark a line'"></span>
                                </button>
                                <div x-show="marking && ! picked" x-cloak class="small text-muted mb-2">Click a line in the template.</div>

                                <template x-if="picked">
                                    <div class="border rounded p-2 mb-2" style="background: #fffaf1;">
                                        <div class="small text-muted mb-1">Line <span x-text="picked.line + 1"></span>: <em x-text="picked.text || '(empty line)'"></em></div>
                                        <div class="qr-seg mb-2">
                                            <label :class="{ 'is-on': pickKind === 'field' }"><input type="radio" value="field" x-model="pickKind">A field</label>
                                            <label :class="{ 'is-on': pickKind === 'writing' }"><input type="radio" value="writing" x-model="pickKind">Writing space</label>
                                            <label :class="{ 'is-on': pickKind === 'signature' }"><input type="radio" value="signature" x-model="pickKind">Signature</label>
                                        </div>
                                        <input type="text" class="form-control form-control-sm mb-2" maxlength="80" x-show="pickKind !== 'signature'" x-model="pickLabel" placeholder="Name on the form, e.g. Background">
                                        <div class="small text-muted mb-2" x-show="pickKind === 'field'">The value is written at the end of this line (or replaces a [hint] on it).</div>
                                        <div class="small text-muted mb-2" x-show="pickKind === 'writing'">Paragraphs are written here (or on a new line under this heading).</div>
                                        <div class="small text-muted mb-2" x-show="pickKind === 'signature'">The signatory's signature goes here, instead of where PDTS put it.</div>
                                        <button type="button" class="btn btn-sm btn-primary" x-on:click="addMark()" :disabled="pickKind !== 'signature' && pickLabel.trim() === ''">Add</button>
                                        <button type="button" class="btn btn-sm btn-link" x-on:click="picked = null">Cancel</button>
                                    </div>
                                </template>

                                <template x-for="mark in marks" :key="mark.line">
                                    <div class="qr-mark-row">
                                        <span><strong x-text="markKindLabel(mark.kind)"></strong><span x-show="mark.label" x-text="': ' + mark.label"></span> <span class="text-muted">· line <span x-text="mark.line + 1"></span></span></span>
                                        <button type="button" class="btn btn-sm btn-link text-danger p-0" x-on:click="removeMark(mark.line)">Remove</button>
                                    </div>
                                </template>
                                <div class="small text-muted mt-1" x-show="marks.length">Marks are applied when you save.</div>
                            </div>
                        </div>
                    @endif

                    @if ($template->isPdf())
                        <div class="small text-muted mb-3"><i class="fas fa-info-circle mr-1"></i>PDF templates are printed as they are, with each document's QR code added. Details are written on the paper by hand.</div>
                    @endif

                    @error('placement') <div class="alert alert-danger small">{{ $message }}</div> @enderror
                </div>
                @endif
            </div>
        </div>
    @endif
</div>
