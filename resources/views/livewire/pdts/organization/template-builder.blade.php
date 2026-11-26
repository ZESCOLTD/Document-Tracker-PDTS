@php
    use App\Models\PdtsDocumentTemplate;
    use App\Services\Pdts\Templates\TemplateDesigner;

    $teal = config('theme.color_sidebar', '#093f42');
    $items = $design['items'] ?? [];
    $isForm = ($design['layout'] ?? 'memo') === 'form';
    $isReport = ($design['layout'] ?? 'memo') === 'report';
    $isCustom = ($design['layout'] ?? 'memo') === 'custom';
    // A letter: no title under the logo, and its own lines (OUR REF, the addressee, "Dear …", Yours faithfully).
    $isLetter = ($design['layout'] ?? 'memo') === 'letter';
    // No title under the logo: a letter, or the title turned off in the settings.
    $noTitle = $isLetter || ! ($design['show_title'] ?? true);
    $numbers = $isReport ? TemplateDesigner::reportNumbers($items) : [];
    $current = $selected !== null ? ($items[$selected] ?? null) : null;
    // The QR code's settings show when it is clicked on the page.
    $showQr = $qrSelected && ! $current;
    $icons = ['text' => 'fa-font', 'long' => 'fa-align-left', 'number' => 'fa-hashtag', 'date' => 'fa-calendar-alt', 'choice' => 'fa-list-ul', 'person' => 'fa-user', 'people' => 'fa-users', 'attachment' => 'fa-paperclip', 'rich' => 'fa-bold', 'table' => 'fa-table'];
    // What the page shows where the author's answer goes.
    $answerHint = fn (array $item) => match ($item['type'] ?? null) {
        'choice' => $item['choices'] ?: 'Choices…',
        'person' => 'A person chosen in PDTS (name, job title)',
        'people' => 'People chosen in PDTS, one per line',
        'attachment' => 'Attached: the file the author adds',
        'rich' => 'Formatted text: bold, italic, lists',
        default => $item['kind'] === 'question' ? TemplateDesigner::QUESTION_TYPES[$item['type']] : 'Filled in by the author',
    };
    // The examples on TO, FROM, CC and REF follow the document type chosen in the settings (a memo's when none is chosen).
    $docType = (string) optional($types->firstWhere('id', (int) $documentTypeId))->name;
    $standardHints = ([
        'Official Letter' => ['to' => 'The Managing Director, ABC Ltd', 'from' => 'Director – Corporate Affairs', 'cc' => 'The Permanent Secretary', 'office_reference' => 'ZESCO/CA/045/2026'],
        'Contract' => ['to' => 'The Supplier, XYZ Ltd', 'from' => 'Director – Procurement', 'cc' => 'Legal Counsel', 'office_reference' => 'PROC/CON/012/2026'],
        'Procurement Document' => ['to' => 'Head – Procurement', 'from' => 'Senior Manager – Stores', 'cc' => 'Director – Finance', 'office_reference' => 'PROC/RFQ/078/2026'],
        'Report' => ['to' => 'Managing Director', 'from' => 'Manager – Operations', 'cc' => 'Board Secretary', 'office_reference' => 'OPS/RPT/003/2026'],
        'Request / Submission' => ['to' => 'Director – Human Resources', 'from' => 'Principal Engineer – Transmission', 'cc' => 'Senior Manager – Finance', 'office_reference' => 'HR/REQ/019/2026'],
    ][$docType] ?? ['to' => 'Director – ICT', 'from' => 'Senior Manager – ICT', 'cc' => 'Managing Director', 'office_reference' => 'ICT/ADM/001/2026'])
        + ['date' => now()->format('j F Y')];
    if ($isLetter) {
        $standardHints = ['office_reference' => 'DEPT/A00/0000/2026', 'cc' => 'Title of person copied'] + $standardHints;
    }
    $palette = [
        'Standard lines' => collect(TemplateDesigner::STANDARD)->map(fn ($label, $name) => ['standard', $name, $label, 'fa-grip-lines'])->values()->all(),
        'Questions' => collect(TemplateDesigner::QUESTION_TYPES)->map(fn ($label, $type) => ['question', $type, $label, $icons[$type]])->values()->all(),
        'Layout' => [['section', null, 'Section heading', 'fa-heading'], ['text', null, 'Fixed text', 'fa-paragraph'], ['note', null, 'Note (small, italic)', 'fa-sticky-note'],
            ['line', null, 'Line', 'fa-minus'], ['space', null, 'Space', 'fa-arrows-alt-v'], ['pagebreak', null, 'Page break (what follows starts a new page)', 'fa-cut'],
            ['signature', null, 'Signature (signed in PDTS)', 'fa-signature'], ['signline', null, 'Signed by hand', 'fa-pen-nib']],
    ];
    $shortLabels = [
        'question.table' => 'Table', 'question.text' => 'Short text', 'question.long' => 'Paragraph', 'question.rich' => 'Formatted',
        'question.person' => 'Person', 'question.people' => 'People', 'question.choice' => 'Choice list', 'question.attachment' => 'Attachment',
        'note.' => 'Note', 'pagebreak.' => 'Page break', 'signature.' => 'Signature', 'signline.' => 'Hand-signed', 'section.' => 'Heading', 'text.' => 'Fixed text',
        'standard.office_reference' => 'REF',
    ];
    $hasError = fn (int $i) => collect($errors->getMessages())->keys()->contains(fn ($k) => str_starts_with($k, 'design.items.'.$i));
@endphp

<div class="container-fluid pt-2 pb-4" x-data="{
        drag: null,
        over: null,
        start(e, data) { this.drag = data; e.dataTransfer.effectAllowed = data.from === 'palette' ? 'copy' : 'move'; e.dataTransfer.setData('text/plain', 'pdts'); },
        hover(e, index) { const r = e.currentTarget.getBoundingClientRect(); this.over = (e.clientY - r.top) < r.height / 2 ? index : index + 1; },
        cell: null,
        hoverCell(e, r, c, p) { const b = e.currentTarget.getBoundingClientRect(); this.over = null; this.cell = { r, c, p: (e.clientY - b.top) < b.height / 2 ? p : p + 1 }; },
        drop() {
            if (this.drag && this.cell) {
                if (this.drag.from === 'palette') { $wire.addItemTo(this.drag.kind, this.drag.type, this.cell.r, this.cell.c, this.cell.p); }
                else { $wire.moveItemTo(this.drag.index, this.cell.r, this.cell.c, this.cell.p); }
            } else if (this.drag && this.over !== null) {
                if (this.drag.from === 'palette') { $wire.addItem(this.drag.kind, this.drag.type, this.over); }
                else if (this.over !== this.drag.index && this.over !== this.drag.index + 1) { $wire.moveItem(this.drag.index, this.over); }
            }
            this.drag = null; this.over = null; this.cell = null;
        },
        end() { this.drag = null; this.over = null; this.cell = null; },
        // The page is a real A4 sheet (794 px wide), shrunk to fit; the viewer can zoom in.
        zoom: 1, zoomMode: 'fit',
        init() {
            // On smaller screens the main menu folds to its icons here, to give the page room (the ☰ button opens it).
            if (window.innerWidth < 1600 && window.innerWidth >= 992) document.body.classList.add('sidebar-collapse');
            try { this.zoomMode = localStorage.getItem('pdts.builder.zoom') || 'fit'; } catch (e) {}
            // The menu slides open or shut: fit the page again when it has.
            document.addEventListener('collapsed.lte.pushmenu', () => setTimeout(() => this.fit(), 350));
            document.addEventListener('shown.lte.pushmenu', () => setTimeout(() => this.fit(), 350));
            if (window.jQuery) { window.jQuery(document).on('collapsed.lte.pushmenu shown.lte.pushmenu', () => setTimeout(() => this.fit(), 350)); }
            this.$nextTick(() => { this.fitHeight(); this.fit(); });
            window.addEventListener('resize', () => { this.fitHeight(); this.fit(); });
        },
        // The three panels fill the window below the bar and each scrolls on its own, so the page itself does not scroll.
        // Set on <html> (outside the component) so Livewire's updates do not wipe it.
        fitHeight() {
            const root = document.documentElement;
            const grid = this.$refs.grid;
            if (! grid || window.innerWidth < 1200) { root.style.removeProperty('--tb-h'); return; }
            const top = grid.getBoundingClientRect().top + window.scrollY;
            root.style.setProperty('--tb-h', Math.max(360, window.innerHeight - top - 12) + 'px');
            const extra = root.scrollHeight - window.innerHeight;
            if (extra > 0) root.style.setProperty('--tb-h', Math.max(360, window.innerHeight - top - 12 - extra) + 'px');
        },
        fit() {
            const wrap = this.$refs.pageWrap;
            const fitted = wrap ? Math.min(1, (wrap.clientWidth - 2) / 794) : 1;
            this.zoom = this.zoomMode === 'fit' ? fitted : Number(this.zoomMode);
        },
        setZoom(mode) {
            this.zoomMode = String(mode);
            try { localStorage.setItem('pdts.builder.zoom', this.zoomMode); } catch (e) {}
            this.fit();
        },
        // The QR code dragged on the page (3.78 px per mm on the A4 sheet).
        dragQr(e, qr) {
            const el = e.currentTarget; const perMm = 3.7807 * this.zoom;
            const start = { x: e.clientX, y: e.clientY, mmX: qr.x, mmY: qr.y };
            let pos = { x: qr.x, y: qr.y };
            el.setPointerCapture(e.pointerId); el.classList.add('is-dragging');
            const move = (m) => {
                pos.x = Math.round(Math.min(210 - qr.size, Math.max(0, start.mmX + (m.clientX - start.x) / perMm)) * 10) / 10;
                pos.y = Math.round(Math.min(293 - qr.size, Math.max(0, start.mmY + (m.clientY - start.y) / perMm)) * 10) / 10;
                el.style.left = (pos.x * 3.7807) + 'px'; el.style.top = (pos.y * 3.7807) + 'px';
            };
            const up = () => {
                el.removeEventListener('pointermove', move); el.removeEventListener('pointerup', up); el.classList.remove('is-dragging');
                if (pos.x !== qr.x || pos.y !== qr.y) $wire.placeQr(pos.x, pos.y); else $wire.selectQr();
            };
            el.addEventListener('pointermove', move); el.addEventListener('pointerup', up);
        },
        zoomStep(d) { this.setZoom(Math.round(Math.min(1.5, Math.max(0.5, this.zoom + d)) * 10) / 10); },
        // Ctrl+Z undoes and Ctrl+Y (or Ctrl+Shift+Z) redoes on the page; inside a text box they stay the box's own.
        keys(e) {
            if (! (e.ctrlKey || e.metaKey) || ['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName) || e.target.isContentEditable) return;
            const k = e.key.toLowerCase();
            if (k === 'z' && ! e.shiftKey) { e.preventDefault(); $wire.undo(); }
            else if (k === 'y' || (k === 'z' && e.shiftKey)) { e.preventDefault(); $wire.redo(); }
        },
    }" x-on:keydown.window="keys($event)">
    @assets
        <script src="{{ asset('vendor/pdfjs/pdf.min.js') }}"></script>
        <script>
            // A folding section of the settings; whether it is open is remembered in this browser.
            // A folding settings section: open as given (Details open while the template has no name), then as the viewer left it.
            window.tbSection = (key, openAtFirst = true) => ({
                open: openAtFirst,
                init() {
                    if (! openAtFirst) return;
                    try { this.open = localStorage.getItem('pdts.builder.section.' + key) !== 'closed'; } catch (e) {}
                },
                toggle() { this.open = ! this.open; try { localStorage.setItem('pdts.builder.section.' + key, this.open ? 'open' : 'closed'); } catch (e) {} },
            });
            // "Preview": the design as the exact PDF, in a window over the builder. Nothing is saved.
            window.pdtsBuilderPreview = (workerSrc) => ({
                open: false, loading: false, failed: '',
                async show(url) {
                    this.open = true; this.loading = true; this.failed = '';
                    const host = this.$refs.pages;
                    host.innerHTML = '';
                    try {
                        window.pdfjsLib.GlobalWorkerOptions.workerSrc = workerSrc;
                        const pdf = await window.pdfjsLib.getDocument({ url, withCredentials: true }).promise;
                        await this.$nextTick();
                        const width = Math.min(900, Math.max(320, host.clientWidth - 32));
                        for (let n = 1; n <= pdf.numPages; n++) {
                            const page = await pdf.getPage(n);
                            const base = page.getViewport({ scale: 1 });
                            const viewport = page.getViewport({ scale: width / base.width });
                            const ratio = window.devicePixelRatio || 1;
                            const canvas = document.createElement('canvas');
                            canvas.width = viewport.width * ratio; canvas.height = viewport.height * ratio;
                            canvas.style.width = viewport.width + 'px'; canvas.style.height = viewport.height + 'px';
                            canvas.className = 'tb-preview-page';
                            host.appendChild(canvas);
                            await page.render({ canvasContext: canvas.getContext('2d'), viewport, transform: ratio !== 1 ? [ratio, 0, 0, ratio, 0, 0] : null }).promise;
                        }
                    } catch (e) {
                        console.error(e);
                        this.failed = 'The preview could not be shown in this browser. Save and use "Try it" instead.';
                    }
                    this.loading = false;
                },
                close() { this.open = false; },
            });
        </script>
    @endassets
    <style>
        .tb-grid { display: grid; grid-template-columns: 250px minmax(0, 1fr) 320px; gap: 1rem; align-items: start; }
        @media (max-width: 1499.98px) and (min-width: 1200px) { .tb-grid { grid-template-columns: 240px minmax(0, 1fr) 270px; gap: .75rem; } }
        .tb-palette { padding: .625rem .75rem; }
        .tb-cell .tb-line { min-width: 0; }
        .tb-cell .tb-line > span { flex-shrink: 0; }
        .tb-cell .tb-blank { min-width: 0; flex: 1 1 auto; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        @media (max-width: 1199.98px) { .tb-grid { grid-template-columns: 230px minmax(0, 1fr); } .tb-settings { grid-column: 1 / -1; } }
        @media (max-width: 767.98px) { .tb-grid { grid-template-columns: 1fr; } }
        .tb-panel { background: #fff; border: 1px solid #dfe6e6; border-radius: .75rem; padding: 1rem; }
        /* The palette and the settings stay in view while the page is designed: each fits the window and scrolls on its own. */
        /* The bar with Undo, Preview and Save stays at the top while the page scrolls. */
        .tb-bar { position: sticky; top: 0; z-index: 30; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: .5rem; background: #f4f6f9; padding: .5rem 0; margin-bottom: .75rem; border-bottom: 1px solid #dfe6e6; }
        .tb-bar .btn-group .btn { min-width: 2.5rem; }
        .tb-sticky { position: sticky; top: 4rem; max-height: calc(100vh - 4.75rem); overflow-y: auto; overscroll-behavior: contain; scrollbar-width: thin; }
        /* The settings end above the support chat button (fixed at the bottom right). */
        .tb-settings.tb-sticky { max-height: calc(100vh - 9rem); }
        .tb-preview { position: fixed; inset: 0; z-index: 10050; background: rgba(15, 30, 30, .55); display: flex; align-items: stretch; justify-content: center; padding: 1.25rem; }
        .tb-preview-box { background: #e9eef0; border-radius: .75rem; width: min(980px, 100%); display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 10px 40px rgba(0, 0, 0, .3); }
        .tb-preview-head { display: flex; justify-content: space-between; align-items: center; gap: .5rem; background: #fff; padding: .625rem 1rem; border-bottom: 1px solid #dfe6e6; }
        .tb-preview-body { overflow: auto; padding: 1rem; flex: 1; }
        .tb-preview-page { display: block; margin: 0 auto 1rem; box-shadow: 0 2px 10px rgba(0, 0, 0, .15); background: #fff; }
        @media (max-width: 1199.98px) { .tb-settings.tb-sticky { position: static; max-height: none; overflow: visible; } }
        @media (max-width: 767.98px) { .tb-sticky { position: static; max-height: none; overflow: visible; } }
        /* Wide screens: the three columns are as tall as the window below the bar (--tb-h, set by fitHeight) and scroll on their own. */
        @media (min-width: 1200px) {
            .tb-grid { height: var(--tb-h, calc(100vh - 8rem)); align-items: stretch; }
            .tb-grid > * { min-height: 0; overflow-y: auto; overscroll-behavior: contain; scrollbar-width: thin; }
            .tb-grid > .tb-sticky { position: static; max-height: none; }
            /* The page's column is the one scroller (both ways); the page inside it does not scroll on its own as well. */
            .tb-grid > main { overflow: auto; }
            .tb-grid .tb-page-wrap { overflow: visible; }
            /* Undo, redo and the zoom stay at the top of the column while the page scrolls under them. */
            .tb-grid > main > .tb-pagebar { position: sticky; top: 0; left: 0; z-index: 20; background: #f4f6f9; padding: .25rem 0; margin-bottom: 0; }
            /* The settings end above the support chat button (fixed at the bottom right). */
            .tb-grid > .tb-settings { margin-bottom: 4.25rem; }
        }
        .tb-group { display: flex; align-items: center; gap: .35rem; font-size: .65rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #3d5555; margin: .5rem 0 .3rem; padding-top: .45rem; border-top: 1px solid #e8eeee; }
        .tb-group i { color: {{ $teal }}; width: .9rem; text-align: center; }
        .tb-group small { margin-left: auto; font-weight: 400; letter-spacing: 0; text-transform: none; color: #8a9a9a; font-size: .65rem; }
        .tb-tools { display: grid; grid-template-columns: 1fr 1fr; gap: .25rem; }
        .tb-tool { display: flex; align-items: center; gap: .3rem; width: 100%; min-width: 0; border: 1px solid #dfe6e6; background: #f9fbfb; border-radius: .4rem; padding: .2rem .4rem; font-size: .7rem; line-height: 1.2; cursor: grab; text-align: left; white-space: nowrap; }
        /* One line each; a name too long ends in "…" (the full name shows on hover). */
        .tb-tool span { min-width: 0; overflow: hidden; text-overflow: ellipsis; }
        .tb-tool:hover:not(:disabled) { border-color: {{ $teal }}; background: #eef5f5; }
        .tb-tool:disabled { opacity: .45; cursor: not-allowed; }
        .tb-tool i { width: 1rem; color: {{ $teal }}; text-align: center; }
        /* A4 at screen size (210 x 297 mm = 794 x 1123 px) with ZESCO's margins (20 mm sides, 17.6 mm top, 23 mm bottom).
           The dashed line every 1123 px is about where a page ends. */
        .tb-page-wrap { overflow-x: auto; padding-bottom: .5rem; }
        .tb-pagebar { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: .5rem; margin-bottom: .25rem; }
        .tb-layouts { display: inline-flex; align-items: center; font-size: .78rem; color: #3d5555; font-weight: 600; }
        .tb-layouts button { border: 1px solid #c9d6d6; background: #fff; color: #3d4b4b; height: 2rem; padding: 0 .75rem; font-size: .8rem; font-weight: 600; margin-left: -1px; }
        .tb-layouts button:first-of-type { border-radius: .375rem 0 0 .375rem; margin-left: 0; }
        .tb-layouts button:last-of-type { border-radius: 0 .375rem .375rem 0; }
        .tb-layouts button.is-on { background: {{ $teal }}; border-color: {{ $teal }}; color: #fff; }
        .tb-zoom { display: flex; align-items: center; justify-content: flex-end; gap: .25rem; font-size: .75rem; color: #6c7a7a; }
        .tb-sec-sum { margin-left: auto; margin-right: .5rem; font-weight: 400; letter-spacing: 0; text-transform: none; color: #6c7a7a; font-size: .72rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 60%; }
        .tb-zoom button { border: 1px solid #dfe6e6; background: #fff; border-radius: .25rem; height: 1.6rem; min-width: 1.75rem; padding: 0 .4rem; font-size: .72rem; color: #3d4b4b; }
        .tb-zoom button:disabled { opacity: .45; cursor: not-allowed; }
        .tb-zoom button.is-on { background: {{ $teal }}; border-color: {{ $teal }}; color: #fff; }
        .tb-page { position: relative; width: 794px; margin: 0 auto; background-color: #fff; background-image: repeating-linear-gradient(to bottom, transparent 0, transparent 1121px, #b9c6c6 1121px, #b9c6c6 1123px); border: 1px solid #d5dede; box-shadow: 0 2px 12px rgba(9, 63, 66, .12); padding: 67px 76px 87px; min-height: 1123px; font-family: {{ config('pdts.builder.font', 'Tahoma') }}, Verdana, sans-serif; }
        .tb-margins { position: absolute; top: 67px; left: 76px; right: 76px; height: 969px; border: 1px dashed #dbe4e4; border-radius: 2px; pointer-events: none; }
        .tb-page-end { position: absolute; left: 0; right: 0; top: 1123px; transform: translateY(-50%); text-align: center; pointer-events: none; }
        .tb-page-end span { background: #fff; color: #9aa5a5; font: 600 10px/1.4 Arial, sans-serif; padding: 0 .5rem; letter-spacing: .04em; }
        .tb-head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #111; padding-bottom: .625rem; margin-bottom: 1rem; }
        .tb-org { color: #075B35; font-weight: 700; font-size: .8125rem; letter-spacing: .02em; }
        .tb-title { font-weight: 700; font-size: 1.125rem; text-transform: uppercase; }
        .tb-qr { width: 52px; height: 52px; border: 1px dashed #9aa5a5; border-radius: .25rem; display: flex; align-items: center; justify-content: center; color: #9aa5a5; font-size: 1.25rem; flex-shrink: 0; }
        .tb-item { position: relative; border: 1px solid transparent; border-radius: .375rem; padding: .375rem .5rem; margin: 0 -.5rem; cursor: pointer; }
        .tb-item:hover { border-color: #cfdcdc; background: #fafcfc; }
        /* The item being set up on the right: a strong outline, a soft glow and an "Editing" tag. */
        .tb-item.is-selected { border: 2px solid {{ $teal }}; background: #eef6f6; box-shadow: inset 4px 0 0 {{ $teal }}, 0 0 0 4px rgba(9, 63, 66, .12); }
        .tb-item.is-selected::before { content: 'Editing'; position: absolute; left: .5rem; bottom: 100%; margin-bottom: -2px; background: {{ $teal }}; color: #fff; font: 700 9px/1.6 Arial, sans-serif; letter-spacing: .06em; text-transform: uppercase; padding: 0 .4rem; border-radius: .25rem .25rem 0 0; z-index: 6; }
        .tb-page:has(.tb-item.is-selected) .tb-item:not(.is-selected):not(:hover) { opacity: .8; }
        .tb-item.has-error { border-color: #dc3545; background: #fff6f6; }
        .tb-item.is-dragging { opacity: .4; }
        /* Above the item, so it never covers what the item shows. */
        .tb-actions { position: absolute; bottom: 100%; right: .25rem; margin-bottom: -2px; z-index: 6; box-shadow: 0 2px 6px rgba(9, 63, 66, .12); display: none; gap: .125rem; background: #fff; border: 1px solid #dfe6e6; border-radius: .375rem; padding: .125rem; }
        .tb-item:hover .tb-actions, .tb-item.is-selected .tb-actions { display: flex; }
        .tb-actions button { border: 0; background: transparent; color: #4a5a5a; width: 1.75rem; height: 1.75rem; border-radius: .25rem; font-size: .75rem; }
        .tb-actions button:hover { background: #eef5f5; color: {{ $teal }}; }
        .tb-handle { color: #9aa5a5; cursor: grab; margin-right: .375rem; }
        .tb-drop-line { height: 3px; background: {{ $teal }}; border-radius: 3px; margin: 2px 0; }
        .tb-line { display: flex; gap: .5rem; font-size: .875rem; }
        .tb-line .tb-l { font-weight: 700; min-width: 8.5rem; }
        .tb-blank { color: #8a9898; border-bottom: 1px dotted #b8c4c4; flex: 1; min-height: 1.25rem; font-style: italic; font-size: .8125rem; }
        .tb-req { color: #dc3545; margin-left: .125rem; }
        .tb-row { display: grid; grid-template-columns: 34% 1fr; border: 1px solid #c9d3d3; border-top-width: 0; font-size: .8125rem; }
        .tb-row > div { padding: .3rem .5rem; } .tb-row > div:first-child { background: #eef3f3; font-weight: 700; border-right: 1px solid #c9d3d3; }
        .tb-row.full { grid-template-columns: 1fr; } .tb-row.full > div:first-child { border-right: 0; }
        .tb-row.first { border-top-width: 1px; }
        .tb-row.section > div { background: #075B35 !important; color: #fff; text-transform: uppercase; }
        .tb-empty { border: 2px dashed #c9d6d6; border-radius: .5rem; padding: 2rem 1rem; text-align: center; color: #6c7a7a; }
        .tb-letterhead { position: relative; text-align: center; margin-bottom: 1.25rem; padding-top: .25rem; }
        .tb-logo { max-height: 92px; max-width: 45%; display: block; margin: 0 auto .25rem; }
        .tb-lh-line { color: #2e7d32; font-weight: 700; font-family: Georgia, serif; font-size: 1rem; }
        .tb-lh-title { font-weight: 800; font-size: 1.5rem; letter-spacing: .01em; margin-top: .125rem; }
        /* The QR code where it prints on the A4 sheet (3.78 px per mm); drag it to move it. */
        .tb-qr-spot { position: absolute; z-index: 5; border: 2px dashed {{ $teal }}; border-radius: .25rem; background: rgba(255, 255, 255, .9); display: flex; align-items: center; justify-content: center; color: {{ $teal }}; cursor: grab; touch-action: none; }
        .tb-qr-spot.is-selected { border-style: solid; background: #eef6f6; box-shadow: 0 0 0 4px rgba(9, 63, 66, .15); }
        .tb-qr-spot.is-dragging { cursor: grabbing; box-shadow: 0 0 0 4px rgba(9, 63, 66, .15); }
        .tb-qr-spot i { font-size: 1.6em; pointer-events: none; }
        .tb-qr-spot span { position: absolute; top: 100%; left: 50%; transform: translateX(-50%); margin-top: 2px; font: 600 9px/1.2 Arial, sans-serif; color: #5a6a6a; white-space: nowrap; pointer-events: none; }
        .tb-sec { border: 1px solid #e3eaea; border-radius: .5rem; margin-bottom: .625rem; background: #fff; }
        .tb-sec-head { display: flex; justify-content: space-between; align-items: center; width: 100%; border: 0; background: #f6f9f9; border-radius: .5rem; padding: .5rem .75rem; font-size: .72rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #3d5555; text-align: left; }
        .tb-sec-head:hover { background: #eef5f5; }
        .tb-sec-head.is-fixed { cursor: default; } .tb-sec-head.is-fixed:hover { background: #f6f9f9; }
        .tb-sec-head > span { white-space: nowrap; flex-shrink: 0; }
        .tb-sec-head i:first-child { color: {{ $teal }}; }
        .tb-sec-chev { transition: transform .15s; color: #8a9a9a; } .tb-sec-chev.is-closed { transform: rotate(-90deg); }
        .tb-sec-body { padding: .625rem .75rem .75rem; }
        .tb-placed { font-size: .65rem; color: #2e7d4f; margin-top: .25rem; line-height: 1.3; }
        /* A setting: a short label on one line, the control under it. */
        /* A setting: the label beside its control, one line each, so the settings fit without scrolling. */
        .tb-field { display: grid; grid-template-columns: 4.5rem minmax(0, 1fr); align-items: center; column-gap: .375rem; margin-bottom: .375rem; }
        .tb-field > label { font-size: .75rem; font-weight: 600; color: #3d4b4b; margin: 0; line-height: 1.2; }
        .tb-field > .invalid-feedback { grid-column: 2; }
        .tb-field .form-control-sm { font-size: .78rem; height: 1.85rem; padding: .2rem .45rem; }
        .tb-field .form-control-sm { min-width: 0; text-overflow: ellipsis; }
        /* Memo / Custom as two halves of one switch: the chosen one is filled, no radio circles taking room. */
        .tb-field .tb-layout-pick { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .25rem; min-width: 0; }
        .tb-field .tb-layout { justify-content: center; padding: .25rem .25rem; font-size: .78rem; min-width: 0; white-space: nowrap; }
        .tb-field .tb-layout input { position: absolute; opacity: 0; pointer-events: none; }
        .tb-field .tb-layout.is-on { background: {{ $teal }}; border-color: {{ $teal }}; color: #fff; }
        .tb-settings { padding: .75rem; overflow-x: hidden !important; }
        .tb-settings .tb-sec-body { padding: .5rem .625rem .5rem; }
        .tb-settings .tb-sec { margin-bottom: .5rem; }
        .tb-qr-corners { display: grid; grid-template-columns: 1fr 1fr; gap: .3rem; }
        .tb-qr-corners button { font-size: .75rem; }
        .tb-pair { display: grid; grid-template-columns: 1fr 1fr; gap: 0 1rem; }
        .tb-pair .tb-item { margin: 0 -.5rem; }
        .tb-crow { border: 1px dashed transparent; border-radius: .375rem; margin: 0 -.5rem .25rem; padding: .125rem .5rem .25rem; }
        .tb-crow:hover { border-color: #d5e0e0; }
        .tb-crow-bar { display: flex; align-items: center; gap: .25rem; font-size: .7rem; color: #6c7a7a; opacity: .35; margin-bottom: .125rem; white-space: nowrap; min-width: 0; }
        .tb-crow-bar > * { flex-shrink: 0; }
        .tb-seg { display: inline-flex; align-items: center; gap: 0; }
        .tb-seg button { border-radius: 0 !important; margin-left: -1px; min-width: 1.6rem; }
        .tb-seg button:first-of-type { border-radius: .25rem 0 0 .25rem !important; margin-left: 0; }
        .tb-seg button:last-of-type { border-radius: 0 .25rem .25rem 0 !important; }
        .tb-seg-label { margin-right: .25rem; }
        @media (max-width: 1599.98px) { .tb-seg-label { display: none; } }
        .tb-crow-bar select { max-width: 6.5rem; }
        .tb-crow:hover .tb-crow-bar { opacity: 1; }
        .tb-crow-bar button, .tb-crow-bar select { border: 1px solid #dfe6e6; background: #fff; border-radius: .25rem; font-size: .7rem; height: 1.5rem; padding: 0 .375rem; color: #3d4b4b; }
        .tb-crow-bar button.is-on { background: {{ $teal }}; border-color: {{ $teal }}; color: #fff; }
        .tb-cgrid { display: grid; gap: .5rem; }
        .tb-cell { min-height: 2.25rem; border: 1px dashed #e2eaea; border-radius: .25rem; padding: .125rem .5rem; }
        .tb-cell .tb-item { margin: 0 -.25rem; }
        .tb-cell-empty { color: #9aa5a5; font-size: .75rem; text-align: center; padding: .5rem 0; }
        .tb-rule { border: 0; border-top: 2px solid #111; margin: .25rem 0 .5rem; }
        .tb-addrow { display: flex; flex-wrap: wrap; align-items: center; gap: .375rem; margin-top: .75rem; font-size: .8125rem; color: #6c7a7a; }
        .tb-s-left { text-align: left; } .tb-s-center { text-align: center; } .tb-s-right { text-align: right; } .tb-s-both { text-align: justify; }
        .tb-s-center .tb-line, .tb-s-right .tb-line { justify-content: flex-end; }
        .tb-s-center .tb-line { justify-content: center; }
        .tb-s-small { font-size: .8rem; } .tb-s-large { font-size: 1.15rem; }
        .tb-s-border { border: 1px solid #9aa5a5; padding: .25rem .5rem; } .tb-s-shaded { background: #eef3f3; padding: .25rem .5rem; }
        .tb-mini { width: 100%; border-collapse: collapse; font-size: .75rem; font-style: normal; }
        .tb-mini th { background: #eef3f3; border: 1px solid #c9d3d3; padding: .2rem .4rem; font-weight: 700; }
        .tb-mini td { border: 1px solid #c9d3d3; padding: .2rem .4rem; height: 1.25rem; }
        .tb-layout-pick { display: grid; grid-template-columns: 1fr 1fr; gap: .4rem; }
        .tb-layout { display: flex; align-items: center; border: 1px solid #dfe6e6; border-radius: .5rem; padding: .4rem .6rem; margin: 0; cursor: pointer; font-size: .8125rem; }
        .tb-layout.is-on { border-color: {{ $teal }}; background: #eef5f5; }
    </style>

    {{-- The bar: the heading and the buttons on one row; stays at the top while the page scrolls. --}}
    <div class="tb-bar" data-test="builder-bar">
        <div class="d-flex align-items-center flex-wrap" style="gap: .75rem; min-width: 0;">
            <a href="{{ route('pdts.organization.document-templates') }}" class="text-muted" title="Back to Document Templates" aria-label="Back to Document Templates"><i class="fas fa-arrow-left"></i></a>
            <h1 class="h5 mb-0">{{ $template ? 'Edit template' : 'Build a template' }}
                @if ($template)<span class="badge badge-light border align-middle" style="font-size: .75rem;">Version {{ $template->version }}</span>@endif
                <i class="fas fa-info-circle text-muted ml-1" style="font-size: .875rem;" title="Drag items from the left onto the page, or click them to add at the end. Drag items on the page to reorder them. Click an item to set it up."></i>
            </h1>
        </div>
        <div class="d-flex flex-wrap" style="gap: .5rem;">
            <a href="{{ route('pdts.organization.document-templates') }}" class="btn btn-sm btn-outline-secondary">Cancel</a>
            <button type="button" class="btn btn-sm btn-outline-primary" wire:click="preview" wire:loading.attr="disabled" wire:target="preview" title="See the exact PDF with sample details, without saving" data-test="preview">
                <span wire:loading.remove wire:target="preview"><i class="fas fa-eye mr-1"></i>Preview</span>
                <span wire:loading wire:target="preview"><i class="fas fa-circle-notch fa-spin mr-1"></i>Making the preview…</span>
            </button>
            <button type="button" class="btn btn-sm btn-primary" wire:click="save" wire:loading.attr="disabled" wire:target="save" data-test="save-template" title="Saves the template; anything that needs fixing is shown on the item">
                <span wire:loading.remove wire:target="save"><i class="fas fa-save mr-1"></i>Save</span>
                <span wire:loading wire:target="save"><i class="fas fa-circle-notch fa-spin mr-1"></i>Saving…</span>
            </button>
        </div>
    </div>

    {{-- The preview window: the exact PDF with sample details; nothing is saved. --}}
    <div wire:ignore x-data="pdtsBuilderPreview(@js(asset('vendor/pdfjs/pdf.worker.min.js')))" x-on:builder-preview.window="show($event.detail.url)" x-on:keydown.escape.window="close()">
        <div class="tb-preview" x-show="open" x-cloak x-on:click.self="close()" role="dialog" aria-modal="true" aria-label="Preview">
            <div class="tb-preview-box">
                <div class="tb-preview-head">
                    <div><strong>Preview</strong> <span class="small text-muted ml-1">With sample details, as a document from it would print. Not saved yet.</span></div>
                    <button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="close()"><i class="fas fa-times mr-1"></i>Close</button>
                </div>
                <div class="tb-preview-body">
                    <div x-show="loading" class="text-center text-muted py-5"><i class="fas fa-circle-notch fa-spin fa-2x d-block mb-2"></i>Opening the preview…</div>
                    <div x-show="failed" class="alert alert-warning" x-text="failed"></div>
                    <div x-ref="pages"></div>
                </div>
            </div>
        </div>
    </div>

    @if ($template && $template->documents_count > 0)
        <div class="alert alert-info small"><i class="fas fa-code-branch mr-1"></i>{{ $template->documents_count }} {{ str('document')->plural($template->documents_count) }} were made from this template. Saving makes version {{ $template->version + 1 }}; those documents keep version {{ $template->version }}.</div>
    @endif
    @if (session('info')) <div class="alert alert-info small" data-test="builder-info"><i class="fas fa-qrcode mr-1"></i>{{ session('info') }}</div> @endif
    @error('design') <div class="alert alert-danger">{{ $message }}</div> @enderror
    @error('design.items') <div class="alert alert-danger">{{ $message }}</div> @enderror
    @if ($errors->any() && ! $errors->has('design') && ! $errors->has('design.items'))
        @php
            // Each problem, with where it is: a template setting, or an item on the page (click to open it).
            $settingNames = ['name' => 'Template name', 'description' => 'Description', 'documentTypeId' => 'Document type', 'design.title' => 'Printed title'];
            $problems = collect($errors->getMessages())->map(function ($messages, $key) use ($items, $settingNames) {
                if (preg_match('/^design\.items\.(\d+)/', $key, $m)) {
                    $item = $items[(int) $m[1]] ?? null;
                    $what = match ($item['kind'] ?? null) {
                        'standard' => rtrim($item['label'] ?: 'Standard line', ':'),
                        'question' => ($item['label'] ?: 'Question').' ('.mb_strtolower(TemplateDesigner::QUESTION_TYPES[$item['type']] ?? 'question').')',
                        'section' => 'Section heading'.($item['label'] ? ' "'.$item['label'].'"' : ''),
                        'note' => 'Note',
                        'signature' => 'Signature block',
                        default => 'An item',
                    };

                    return ['index' => (int) $m[1], 'where' => 'Item '.((int) $m[1] + 1).': '.$what, 'message' => $messages[0]];
                }

                return ['index' => null, 'where' => $settingNames[$key] ?? 'Template settings', 'message' => $messages[0]];
            })->values();
        @endphp
        <div class="alert alert-danger small mb-3" data-test="builder-problems">
            <div class="font-weight-bold mb-1"><i class="fas fa-exclamation-circle mr-1"></i>Please fix {{ $problems->count() === 1 ? 'this' : 'these' }} first:</div>
            <ul class="mb-0 pl-3">
                @foreach ($problems as $problem)
                    <li>
                        <button type="button" class="btn btn-link btn-sm p-0 align-baseline text-white font-weight-bold" style="text-decoration: underline;" wire:click="select({{ $problem['index'] === null ? 'null' : $problem['index'] }})">{{ $problem['where'] }}</button>:
                        {{ $problem['message'] }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="tb-grid" x-ref="grid">
        {{-- Palette --}}
        <aside class="tb-panel tb-palette tb-sticky" aria-label="Items to add">
            <div class="font-weight-bold small">Add to the template</div>
            <div class="small text-muted" style="font-size: .7rem;">Drag onto the page, or click to add.</div>
            @foreach ($palette as $group => $tools)
                <div class="tb-group"><i class="fas {{ ['Standard lines' => 'fa-grip-lines', 'Questions' => 'fa-question-circle', 'Layout' => 'fa-th-large'][$group] ?? 'fa-circle' }}" aria-hidden="true"></i>{{ $group }}<small>{{ ['Standard lines' => 'once each', 'Questions' => 'the author answers', 'Layout' => 'fixed on the page'][$group] ?? '' }}</small></div>
                @php
                    // Standard lines go on once: those on the page leave the list, and are named under it.
                    $placed = collect($tools)->filter(fn ($t) => $t[0] === 'standard' && ! $this->canAdd($t[0], $t[1]))->pluck(2)->all();
                @endphp
                <div class="tb-tools">
                    @foreach ($tools as [$kind, $type, $label, $icon])
                        @continue($kind === 'standard' && in_array($label, $placed, true))
                        @php
                            $allowed = $this->canAdd($kind, $type);
                            // Short names keep the palette compact; the full description shows on hover.
                            $short = $shortLabels[$kind.'.'.$type] ?? $label;
                        @endphp
                        <button type="button" class="tb-tool" @disabled(! $allowed)
                            draggable="{{ $allowed ? 'true' : 'false' }}"
                            x-on:dragstart="start($event, { from: 'palette', kind: @js($kind), type: @js($type) })" x-on:dragend="end()"
                            wire:click="addItem(@js($kind), @js($type))"
                            title="{{ $label }}{{ $allowed ? '' : ' (already on the template)' }}"
                            data-test="add-{{ $kind }}{{ $type ? '-'.$type : '' }}">
                            <i class="fas {{ $icon }}" aria-hidden="true"></i><span>{{ $short }}</span>
                        </button>
                    @endforeach
                </div>
                @if ($placed)
                    <div class="tb-placed" data-test="standard-placed"><i class="fas fa-check mr-1" aria-hidden="true"></i>{{ count($placed) === count($tools) ? 'All on the page' : 'On the page' }}: {{ implode(' · ', $placed) }}</div>
                @endif
            @endforeach
        </aside>

        {{-- The page --}}
        <main>
            <div class="tb-pagebar">
            {{-- The page's tools together: undo and redo beside the zoom. --}}
            <div class="tb-zoom" role="group" aria-label="Undo and redo">
                <button type="button" wire:click="undo" wire:loading.attr="disabled" @disabled(! $canUndo) title="Undo (Ctrl+Z)" aria-label="Undo" data-test="undo"><i class="fas fa-undo mr-1"></i>Undo</button>
                <button type="button" wire:click="redo" wire:loading.attr="disabled" @disabled(! $canRedo) title="Redo (Ctrl+Y)" aria-label="Redo" data-test="redo"><i class="fas fa-redo mr-1"></i>Redo</button>
            </div>
            <div class="tb-zoom" data-test="page-zoom">
                <span class="mr-1">A4</span>
                <button type="button" x-on:click="zoomStep(-0.1)" title="Smaller" aria-label="Smaller"><i class="fas fa-minus"></i></button>
                <button type="button" x-on:click="setZoom('fit')" :class="{ 'is-on': zoomMode === 'fit' }" title="Fit the page to the space">Fit</button>
                <button type="button" x-on:click="setZoom(1)" :class="{ 'is-on': zoomMode === '1' }" title="Actual size">100%</button>
                <button type="button" x-on:click="zoomStep(0.1)" title="Larger" aria-label="Larger"><i class="fas fa-plus"></i></button>
                <span class="ml-1" style="min-width: 2.5rem;" x-text="Math.round(zoom * 100) + '%'"></span>
            </div>
            </div>
            <div class="tb-page-wrap" x-ref="pageWrap">
            <div class="tb-page" x-bind:style="'zoom: ' + zoom" x-on:dragover.prevent x-on:drop.prevent="drop()" data-test="a4-page">
                <div class="tb-margins" aria-hidden="true" title="Page margins"></div>
                <div class="tb-page-end" aria-hidden="true"><span>ABOUT WHERE PAGE 1 ENDS</span></div>
                @php $qr = $design['qr']; $mm = 3.7807; @endphp
                <div class="tb-qr-spot {{ $showQr ? 'is-selected' : '' }}" data-test="qr-spot" title="The QR code and tracking reference are added here automatically when a document is registered. Click for its settings, or drag to move it."
                    style="left: {{ $qr['x'] * $mm }}px; top: {{ $qr['y'] * $mm }}px; width: {{ $qr['size'] * $mm }}px; height: {{ $qr['size'] * $mm }}px;"
                    x-on:pointerdown.prevent="dragQr($event, @js($qr))">
                    <i class="fas fa-qrcode"></i><span>QR code{{ $qr['page'] === 'all' ? ' · every page' : '' }}</span>
                </div>
                @php $shownTitle = ($design['title'] ?? '') !== '' ? $design['title'] : ($name !== '' ? $name : 'Template title'); @endphp
                @if ($letterhead)
                    <div class="tb-letterhead" data-test="letterhead-preview">
                        @if ($letterhead['image'])<img src="{{ $letterhead['image'] }}" alt="Letterhead logo" class="tb-logo">@endif
                        @foreach ($letterhead['lines'] as $line)
                            @if ($line['title'])
                                @unless ($noTitle)<div class="tb-lh-title">{{ mb_strtoupper($shownTitle) }}</div>@endunless
                            @else
                                <div class="tb-lh-line">{{ $line['text'] }}</div>
                            @endif
                        @endforeach
                        @unless ($noTitle || collect($letterhead['lines'])->contains('title', true))
                            <div class="tb-lh-title">{{ mb_strtoupper($shownTitle) }}</div>
                        @endunless
                    </div>
                @else
                    <div class="tb-head">
                        <div>
                            <div class="tb-org">{{ mb_strtoupper($organisation) }}</div>
                            @unless ($noTitle)<div class="tb-title">{{ $shownTitle }}</div>@endunless
                        </div>
                        <div style="width: 60px;"></div>
                    </div>
                @endif

                @if ($isCustom)
                    @foreach ($grid as $r => $gridRow)
                        <div class="tb-crow" wire:key="row-{{ $gridRow['id'] }}" data-test="row-{{ $r }}">
                            <div class="tb-crow-bar">
                                <span class="mr-1" title="Row {{ $r + 1 }}">Row {{ $r + 1 }}</span>
                                <span class="tb-seg" role="group" aria-label="Columns in row {{ $r + 1 }}"><span class="tb-seg-label">Columns</span>
                                @foreach ([1, 2, 3] as $n)
                                    <button type="button" class="{{ $gridRow['columns'] === $n ? 'is-on' : '' }}" wire:click="setRowColumns({{ $r }}, {{ $n }})" title="{{ $n }} {{ $n === 1 ? 'column' : 'columns' }}" aria-pressed="{{ $gridRow['columns'] === $n ? 'true' : 'false' }}">{{ $n }}</button>
                                @endforeach
                                </span>
                                <button type="button" class="{{ $gridRow['line'] ? 'is-on' : '' }}" wire:click="toggleRowLine({{ $r }})" title="A line under this row" aria-label="A line under this row" aria-pressed="{{ $gridRow['line'] ? 'true' : 'false' }}"><i class="fas fa-minus"></i></button>
                                <select wire:model.live="design.rows.{{ $r }}.space" title="Space above this row" aria-label="Space above this row">
                                    <option value="none">no space</option><option value="small">small space</option><option value="large">large space</option>
                                </select>
                                <span class="ml-auto"></span>
                                <button type="button" wire:click="moveRow({{ $r }}, -1)" title="Move the row up" @disabled($r === 0)><i class="fas fa-arrow-up"></i></button>
                                <button type="button" wire:click="moveRow({{ $r }}, 1)" title="Move the row down" @disabled($r === count($grid) - 1)><i class="fas fa-arrow-down"></i></button>
                                @if (array_merge(...$gridRow['cells']))
                                    {{-- PDTS's own question box, never the browser's "localhost says" pop-up. --}}
                                    <button type="button" x-data x-on:click="window.pdtsConfirm(@js('Remove row '.($r + 1).' and the items in it?'), () => $wire.removeRow({{ $r }}))" title="Remove the row and what is in it" data-test="remove-row-{{ $r }}"><i class="far fa-trash-alt"></i></button>
                                @else
                                    <button type="button" wire:click="removeRow({{ $r }})" title="Remove the row" data-test="remove-row-{{ $r }}"><i class="far fa-trash-alt"></i></button>
                                @endif
                            </div>
                            @if ($gridRow['space'] !== 'none')<div style="height: {{ $gridRow['space'] === 'large' ? '1.25rem' : '.5rem' }};"></div>@endif
                            <div class="tb-cgrid" style="grid-template-columns: repeat({{ $gridRow['columns'] }}, minmax(0, 1fr));">
                                @foreach ($gridRow['cells'] as $c => $cell)
                                    <div class="tb-cell" data-test="cell-{{ $r }}-{{ $c }}" x-on:dragover.prevent="cell = { r: {{ $r }}, c: {{ $c }}, p: {{ count($cell) }} }; over = null">
                                        @foreach ($cell as $p => $id)
                                            @continue(! isset($indexById[$id]))
                                            <div x-show="drag && cell && cell.r === {{ $r }} && cell.c === {{ $c }} && cell.p === {{ $p }}" class="tb-drop-line" x-cloak></div>
                                            @include('livewire.pdts.organization.template-builder-item', ['i' => $indexById[$id], 'cellPos' => [$r, $c, $p]])
                                        @endforeach
                                        <div x-show="drag && cell && cell.r === {{ $r }} && cell.c === {{ $c }} && cell.p === {{ count($cell) }}" class="tb-drop-line" x-cloak></div>
                                        @if (! $cell)<div class="tb-cell-empty">Drop items here</div>@endif
                                    </div>
                                @endforeach
                            </div>
                            @if ($gridRow['line'])<hr class="tb-rule">@endif
                        </div>
                    @endforeach
                    <div class="tb-addrow" data-test="add-row">
                        <span>Add a row:</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="addRow(1)">1 column</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="addRow(2)">2 columns</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="addRow(3)">3 columns</button>
                    </div>
                @else
                @forelse ($rows as $row)
                    @if (count($row) > 1)<div class="tb-pair">@endif
                    @foreach ($row as $i)
                    @include('livewire.pdts.organization.template-builder-item', ['cellPos' => null])
                    @endforeach
                    @if (count($row) > 1)</div>@endif
                @empty
                    <div class="tb-empty" x-on:dragover.prevent="over = 0"><i class="fas fa-hand-pointer fa-lg d-block mb-2"></i>Drag items here from the left, or click one to add it.</div>
                @endforelse

                @if ($items)
                    <div x-show="over === {{ count($items) }} && drag" class="tb-drop-line" x-cloak></div>
                    <div style="height: 2.5rem;" x-on:dragover.prevent="over = {{ count($items) }}"></div>
                @endif
                @endif
            </div>
            </div>
        </main>

        {{-- Settings --}}
        <aside class="tb-panel tb-sticky tb-settings" aria-label="Settings">
            {{-- Rebuilt for each item and position, so the inputs always edit the item shown. --}}
            <div wire:key="settings-{{ $showQr ? 'qr' : ($current ? $selected.'-'.$current['id'] : 'template') }}">
            @if ($showQr)
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="font-weight-bold"><i class="fas fa-qrcode mr-1"></i>QR code</div>
                    <button type="button" class="btn btn-link btn-sm p-0" wire:click="select(null)">Template settings</button>
                </div>
                <div data-test="qr-settings">
                    <div class="small text-muted mb-2">Drag it on the page, or pick a corner. The tracking reference prints under it.</div>
                    <div class="tb-qr-corners mb-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="qrCorner('top-left')" data-test="qr-top-left"><i class="fas fa-arrow-up" style="display: inline-block; transform: rotate(-45deg);"></i> Top left</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="qrCorner('top-right')" data-test="qr-top-right"><i class="fas fa-arrow-up" style="display: inline-block; transform: rotate(45deg);"></i> Top right</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="qrCorner('bottom-left')"><i class="fas fa-arrow-down" style="display: inline-block; transform: rotate(45deg);"></i> Bottom left</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="qrCorner('bottom-right')"><i class="fas fa-arrow-down" style="display: inline-block; transform: rotate(-45deg);"></i> Bottom right</button>
                    </div>
                    <div class="d-flex align-items-center mb-2" style="gap: .5rem;">
                        <label class="small mb-0" for="tb-qr-size">Size</label>
                        <input id="tb-qr-size" type="range" class="custom-range" min="15" max="45" step="1" wire:model.live.debounce.250ms="design.qr.size">
                        <span class="small text-nowrap" style="min-width: 3rem;">{{ (int) $design['qr']['size'] }} mm</span>
                    </div>
                    <div class="btn-group btn-group-sm btn-group-toggle w-100 mb-1" role="group" aria-label="Pages with the QR code">
                        <label class="btn btn-outline-secondary {{ $design['qr']['page'] === 'first' ? 'active' : '' }}"><input type="radio" value="first" wire:model.live="design.qr.page"> First page</label>
                        <label class="btn btn-outline-secondary {{ $design['qr']['page'] === 'all' ? 'active' : '' }}"><input type="radio" value="all" wire:model.live="design.qr.page"> Every page</label>
                    </div>
                    <div class="small text-muted">{{ rtrim(rtrim(number_format($design['qr']['x'], 1), '0'), '.') }} mm from the left, {{ rtrim(rtrim(number_format($design['qr']['y'], 1), '0'), '.') }} mm from the top.@if ($design['qr'] != app(TemplateDesigner::class)->standardQr($design['letterhead'] ?? null)) <button type="button" class="btn btn-link btn-sm p-0 align-baseline" wire:click="qrStandard">Standard spot</button>@endif
                    </div>
                    @if ($design['qr']['size'] < 20)<div class="small text-muted">At least 20 mm scans easily from a phone.</div>@endif
                </div>
            @elseif ($current)
                @php
                    $base = 'design.items.'.$selected;
                    $kindName = match ($current['kind']) {
                        'standard' => 'Standard line',
                        'question' => TemplateDesigner::QUESTION_TYPES[$current['type']].' question',
                        'section' => 'Section heading',
                        'note' => 'Note',
                        'text' => 'Fixed text',
                        'line' => 'Line',
                        'space' => 'Space',
                        'pagebreak' => 'Page break',
                        'signline' => 'Signed by hand',
                        'signature' => 'Signature block',
                    };
                @endphp
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="font-weight-bold">{{ $kindName }}</div>
                    <button type="button" class="btn btn-link btn-sm p-0" wire:click="select(null)">Template settings</button>
                </div>
                @error($base) <div class="text-danger small mb-2">{{ $message }}</div> @enderror

                @if (in_array($current['kind'], ['standard', 'question', 'section', 'signature', 'signline'], true))
                    <div class="form-group mb-2">
                        <label class="small font-weight-bold mb-1" for="tb-label">{{ match ($current['kind']) { 'section' => 'Heading', 'signature' => $isLetter ? 'Closing (optional)' : 'Line above the signature (optional)', 'signline' => 'Heading, e.g. Approved by', default => 'Label' } }}</label>
                        <input id="tb-label" type="text" class="form-control form-control-sm @error($base.'.label') is-invalid @enderror" maxlength="80" wire:model.live.debounce.300ms="{{ $base }}.label"
                            placeholder="{{ $current['kind'] === 'signature' ? ($isLetter ? 'Yours faithfully,' : 'e.g. Approved by') : '' }}">
                        @error($base.'.label') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                @endif

                @if ($current['kind'] === 'question')
                    <div class="form-group mb-2">
                        <label class="small font-weight-bold mb-1" for="tb-type">Answer type</label>
                        <select id="tb-type" class="form-control form-control-sm" wire:model.live="{{ $base }}.type">
                            @foreach (TemplateDesigner::QUESTION_TYPES as $value => $text)
                                <option value="{{ $value }}">{{ $text }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if ($current['type'] === 'table')
                        <div class="form-group mb-2">
                            <label class="small font-weight-bold mb-1" for="tb-columns">Column headings, separated by commas</label>
                            <input id="tb-columns" type="text" class="form-control form-control-sm @error($base.'.columns') is-invalid @enderror" wire:model.live.debounce.300ms="{{ $base }}.columns" placeholder="e.g. Item, Quantity, Unit cost, Total">
                            @error($base.'.columns') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            <div class="small text-muted">Up to {{ TemplateDesigner::MAX_TABLE_COLUMNS }} columns. The author adds as many rows as they need.</div>
                        </div>
                    @endif
                    @if ($current['type'] === 'choice')
                        <div class="form-group mb-2">
                            <label class="small font-weight-bold mb-1" for="tb-choices">Choices, separated by commas</label>
                            <input id="tb-choices" type="text" class="form-control form-control-sm @error($base.'.choices') is-invalid @enderror" wire:model.live.debounce.300ms="{{ $base }}.choices" placeholder="e.g. Annual, Sick, Compassionate">
                            @error($base.'.choices') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    @endif
                    @if (in_array($current['type'], TemplateDesigner::PARAGRAPH_TYPES, true))
                        <div class="custom-control custom-switch mb-2">
                            <input type="checkbox" class="custom-control-input" id="tb-showlabel" wire:model.live="{{ $base }}.show_label">
                            <label class="custom-control-label small" for="tb-showlabel">Print the label as a heading above the text</label>
                        </div>
                    @elseif (in_array($current['type'], ['text', 'number', 'date', 'choice'], true))
                        <div class="form-group mb-2">
                            <label class="small font-weight-bold mb-1" for="tb-auto">Filled in automatically</label>
                            <select id="tb-auto" class="form-control form-control-sm" wire:model.live="{{ $base }}.auto">
                                @foreach (PdtsDocumentTemplate::AUTO_FILL as $value => $text)
                                    <option value="{{ $value }}">{{ $text }}</option>
                                @endforeach
                            </select>
                            <div class="small text-muted">The author can still change it.</div>
                        </div>
                    @endif
                @endif

                @if (in_array($current['kind'], ['standard', 'question'], true))
                    @if (! ($current['kind'] === 'standard' && $current['name'] === 'subject'))
                        <div class="custom-control custom-switch mb-2">
                            <input type="checkbox" class="custom-control-input" id="tb-required" wire:model.live="{{ $base }}.required">
                            <label class="custom-control-label small" for="tb-required">Required</label>
                        </div>
                    @else
                        <div class="small text-muted mb-2">The subject is always asked for: it is how the document is tracked.</div>
                    @endif
                    <div class="form-group mb-2">
                        <label class="small font-weight-bold mb-1" for="tb-help">Help text on the form (optional)</label>
                        <input id="tb-help" type="text" class="form-control form-control-sm" maxlength="200" wire:model.live.debounce.300ms="{{ $base }}.help">
                    </div>
                @endif

                @if ($current['kind'] === 'signline')
                    <div class="small text-muted mb-2">A line to sign on by hand, with "Name, signature and date" under it. Only the signature block is signed in PDTS.</div>
                @endif
                @if (in_array($current['kind'], ['line', 'space', 'pagebreak'], true))
                    <div class="small text-muted mb-2">{{ ['line' => 'A line across the page (or the column).', 'space' => 'Blank space of about one line.', 'pagebreak' => 'What follows starts on a new page, e.g. an annex or a second form.'][$current['kind']] }}</div>
                @endif
                @if ($current['kind'] === 'line')
                    <div class="form-group mb-2" data-test="line-weight">
                        <label for="tb-line-weight" class="small font-weight-bold mb-1">Thickness</label>
                        <select id="tb-line-weight" class="form-control form-control-sm" wire:model.live="{{ $base }}.weight">
                            @foreach (\App\Services\Pdts\Templates\TemplateDesigner::LINE_WEIGHTS as $value => [$text, $size])
                                <option value="{{ $value }}">{{ $text }} ({{ rtrim(rtrim(number_format($size / 8, 2), '0'), '.') }} pt)</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                @if ($isCustom && ! in_array($current['kind'], ['line', 'space', 'pagebreak'], true))
                    <div class="border-top pt-2 mt-2" data-test="item-style">
                        <div class="small font-weight-bold mb-2">Style</div>
                        <div class="form-row">
                            <div class="form-group col-6 mb-2">
                                <label class="small mb-1" for="tb-align">Alignment</label>
                                <select id="tb-align" class="form-control form-control-sm" wire:model.live="{{ $base }}.style.align">
                                    @foreach (TemplateDesigner::ALIGNMENTS as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
                                </select>
                            </div>
                            <div class="form-group col-6 mb-2">
                                <label class="small mb-1" for="tb-size">Size</label>
                                <select id="tb-size" class="form-control form-control-sm" wire:model.live="{{ $base }}.style.size">
                                    @foreach (TemplateDesigner::SIZES as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
                                </select>
                            </div>
                        </div>
                        <div class="d-flex flex-wrap mb-2" style="gap: .75rem;">
                            @foreach (['bold' => 'Bold', 'italic' => 'Italic', 'caps' => 'Capitals'] as $key => $text)
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="tb-{{ $key }}" wire:model.live="{{ $base }}.style.{{ $key }}">
                                    <label class="custom-control-label small" for="tb-{{ $key }}">{{ $text }}</label>
                                </div>
                            @endforeach
                            @if (in_array($current['kind'], ['standard', 'question', 'note', 'text'], true))
                                <div class="custom-control custom-checkbox" title="No space after each line, e.g. an address block, or &quot;Yours faithfully,&quot; above ZESCO LIMITED">
                                    <input type="checkbox" class="custom-control-input" id="tb-tight" wire:model.live="{{ $base }}.style.tight" data-test="style-tight">
                                    <label class="custom-control-label small" for="tb-tight">Lines close together</label>
                                </div>
                            @endif
                        </div>
                        @if (in_array($current['kind'], ['standard', 'question'], true))
                            <div class="form-group mb-2">
                                <label class="small mb-1" for="tb-labelmode">Label</label>
                                <select id="tb-labelmode" class="form-control form-control-sm" wire:model.live="{{ $base }}.style.label">
                                    @foreach (TemplateDesigner::LABEL_MODES as $value => $text)
                                        @continue(in_array($value, ['inline', 'plain'], true) && TemplateDesigner::isParagraph($current))
                                        <option value="{{ $value }}">{{ $text }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                        <div class="form-group mb-2">
                            <label class="small mb-1" for="tb-box">Box</label>
                            <select id="tb-box" class="form-control form-control-sm" wire:model.live="{{ $base }}.style.box">
                                @foreach (TemplateDesigner::BOXES as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
                            </select>
                        </div>
                    </div>
                @endif

                @if (in_array($current['kind'], ['note', 'text'], true))
                    <div class="form-group mb-2">
                        <label class="small font-weight-bold mb-1" for="tb-text">Text printed on every document</label>
                        <textarea id="tb-text" rows="4" class="form-control form-control-sm @error($base.'.text') is-invalid @enderror" maxlength="2000" wire:model.live.debounce.300ms="{{ $base }}.text"></textarea>
                        @error($base.'.text') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                @endif

                @if ($current['kind'] === 'signature')
                    <div class="custom-control custom-switch mb-2">
                        <input type="checkbox" class="custom-control-input" id="tb-jobtitle" wire:model.live="{{ $base }}.job_title" data-test="signature-job-title">
                        <label class="custom-control-label small" for="tb-jobtitle">Job title under the name</label>
                    </div>
                    <div class="small text-muted">The signatory signs here in PDTS, and their name is printed under the signature. Without a signature block, documents from this template are issued without a signature.</div>
                @endif
            @else
                <div class="font-weight-bold small mb-2">Template settings</div>
                {{-- Details fold away once the template has a name (a new one opens them); the page settings are one line each. --}}
                <section class="tb-sec" x-data="tbSection('details', @js($name === ''))" data-test="settings-details">
                    <button type="button" class="tb-sec-head" x-on:click="toggle()" :aria-expanded="open.toString()"><span><i class="fas fa-id-card fa-fw mr-1"></i>Details</span><small class="tb-sec-sum" x-show="! open">{{ $name !== '' ? $name : 'No name yet' }}</small><i class="fas fa-chevron-down tb-sec-chev" :class="{ 'is-closed': ! open }"></i></button>
                    <div class="tb-sec-body" x-show="open">
                        <div class="tb-field">
                            <label for="tb-name">Name</label>
                            <input id="tb-name" type="text" class="form-control form-control-sm @error('name') is-invalid @enderror" maxlength="150" wire:model.live.debounce.300ms="name" placeholder="e.g. Leave Request">
                            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        {{-- No printed title to set when there is no title. --}}
                        @unless ($noTitle)
                        <div class="tb-field">
                            <label for="tb-title">Printed title</label>
                            <input id="tb-title" type="text" class="form-control form-control-sm" maxlength="120" wire:model.live.debounce.300ms="design.title" placeholder="Same as the name" title="Optional. The title printed on the document; the name when left empty.">
                        </div>
                        @endunless
                        <div class="tb-field">
                            <label for="tb-desc">Description</label>
                            <input id="tb-desc" type="text" class="form-control form-control-sm" maxlength="500" wire:model.blur="description" placeholder="Optional" title="Optional. Shown to authors when they choose a template.">
                        </div>
                        <div class="tb-field mb-0">
                            <label for="tb-type-doc">Document type</label>
                            <select id="tb-type-doc" class="form-control form-control-sm" wire:model.live="documentTypeId">
                                <option value="">Chosen by the author</option>
                                @foreach ($types as $type)
                                    <option value="{{ $type->id }}">{{ $type->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </section>
                <section class="tb-sec" data-test="settings-layout">
                    <div class="tb-sec-head is-fixed"><span><i class="fas fa-file-alt fa-fw mr-1"></i>Page</span></div>
                    <div class="tb-sec-body">
                        <div class="tb-field">
                            <label>Layout</label>
                            <div class="tb-layout-pick">
                                @foreach (TemplateDesigner::LAYOUTS as $value => $text)
                                    {{-- Memo and Custom are offered; an older template keeps showing its own style. --}}
                                    @continue(! in_array($value, TemplateDesigner::OFFERED_LAYOUTS, true) && ($design['layout'] ?? 'memo') !== $value)
                                    <label class="tb-layout {{ ($design['layout'] ?? 'memo') === $value ? 'is-on' : '' }}" title="{{ $text }}" data-test="layout-{{ $value }}">
                                        <input type="radio" class="mr-1" value="{{ $value }}" wire:model.live="design.layout"> {{ ['memo' => 'Memo', 'letter' => 'Letter', 'custom' => 'Blank', 'form' => 'Form (older)', 'report' => 'Report (older)'][$value] ?? $value }}
                                    </label>
                                @endforeach
                            </div>
                        </div>
                        {{-- A letter never has a title; any other document can go without one. --}}
                        @unless ($isLetter)
                            <div class="tb-field">
                                <label for="tb-showtitle">Title</label>
                                <div class="custom-control custom-switch">
                                    <input type="checkbox" class="custom-control-input" id="tb-showtitle" wire:model.live="design.show_title" data-test="show-title">
                                    <label class="custom-control-label small" for="tb-showtitle">Under the logo</label>
                                </div>
                            </div>
                        @endunless
                        {{-- Asked only when an uploaded template has a really different letterhead; otherwise it is always the ZESCO one. --}}
                        @if ($letterheads->isNotEmpty())
                            <div class="tb-field mb-0" data-test="settings-look">
                                <label for="tb-letterhead">Letterhead</label>
                                <select id="tb-letterhead" class="form-control form-control-sm" wire:model.live="design.letterhead" data-test="letterhead-choice">
                                    <option value="">ZESCO (standard)</option>
                                    @foreach ($letterheads as $option)
                                        <option value="{{ $option->id }}">From: {{ $option->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                    </div>
                </section>
            @endif
            </div>
        </aside>
    </div>
</div>
