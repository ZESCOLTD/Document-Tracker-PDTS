<div class="container-fluid py-4">
    @assets
        <script src="{{ asset('vendor/pdfjs/pdf.min.js') }}"></script>
        <script>
            // "Try it": the template as the exact PDF a document would be, with sample details.
            window.pdtsTryIt = (url, workerSrc) => ({
                loading: false, failed: '', shown: false, open: false,
                // Hidden and shown again without making it again.
                hide() { this.open = false; },
                async show() {
                    if (this.shown) { this.open = true; return; }
                    this.loading = true; this.failed = '';
                    try {
                        window.pdfjsLib.GlobalWorkerOptions.workerSrc = workerSrc;
                        const pdf = await window.pdfjsLib.getDocument({ url, withCredentials: true }).promise;
                        const host = this.$refs.pages;
                        const width = Math.max(320, host.clientWidth - 24);
                        for (let n = 1; n <= pdf.numPages; n++) {
                            const page = await pdf.getPage(n);
                            const viewport = page.getViewport({ scale: width / page.getViewport({ scale: 1 }).width });
                            const ratio = window.devicePixelRatio || 1;
                            const canvas = document.createElement('canvas');
                            canvas.width = viewport.width * ratio; canvas.height = viewport.height * ratio;
                            canvas.style.width = viewport.width + 'px'; canvas.style.height = viewport.height + 'px';
                            canvas.style.display = 'block'; canvas.style.margin = '0 auto 12px'; canvas.style.boxShadow = '0 1px 6px rgba(0,0,0,.15)';
                            host.appendChild(canvas);
                            await page.render({ canvasContext: canvas.getContext('2d'), viewport, transform: ratio !== 1 ? [ratio, 0, 0, ratio, 0, 0] : null }).promise;
                        }
                        this.shown = true;
                        this.open = true;
                    } catch (e) {
                        this.failed = 'The sample could not be made. Download a Word sample from the QR code page instead.';
                    }
                    this.loading = false;
                },
            });
        </script>
    @endassets

    <style>
        .tr-table td, .tr-table th { vertical-align: middle; }
        .tr-table td > span.small { display: inline-block; padding-left: .5rem; }
        .tr-table { table-layout: fixed; min-width: 760px; }
        .tr-table th { font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; color: #6c7a7a; }
    </style>

    <div class="d-flex flex-wrap justify-content-between align-items-start mb-3" style="gap: .5rem;">
        <div>
            <a href="{{ route('pdts.organization.document-templates') }}" class="small text-muted"><i class="fas fa-arrow-left mr-1"></i>Document Templates</a>
            <h1 class="h3 mb-1 mt-1">{{ $template->name }} <span class="badge badge-light border align-middle" style="font-size: .8rem;">Version {{ $template->version }}</span></h1>
            <p class="text-muted mb-0">{{ $template->isBuilt() ? 'Try it before authors use it. To change it, edit it in the builder.' : 'Set how the form looks, and try it before authors use it.' }}</p>
        </div>
        <div class="d-flex flex-wrap" style="gap: .5rem;">
            @if ($template->isBuilt() && ! $template->isSuperseded())
                <a href="{{ route('pdts.organization.document-templates.edit-design', $template) }}" class="btn btn-outline-secondary"><i class="fas fa-th-list mr-1"></i>Edit in the builder</a>
            @endif
            {{-- Uploaded files only: a built template's QR code is placed in the builder. --}}
            @if ($canMark && ! $template->isBuilt())
                <a href="{{ route('pdts.organization.document-templates.qr', $template) }}" class="btn btn-outline-secondary"><i class="fas fa-highlighter mr-1"></i>QR code and marked lines</a>
            @endif
            <a href="{{ route('pdts.organization.document-templates') }}" class="btn btn-primary"><i class="fas fa-check mr-1"></i>Done</a>
        </div>
    </div>

    @if (session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif
    @if (session('error')) <div class="alert alert-danger">{{ session('error') }}</div> @endif
    @if ($template->previousVersion)
        <div class="alert alert-info small"><i class="fas fa-code-branch mr-1"></i>This is version {{ $template->version }}. Version {{ $template->previousVersion->version }} stays for the documents already made from it{{ $template->isConfigured() ? ' and is no longer offered to authors' : '; it is replaced once this version is saved' }}.</div>
    @endif

    @if ($problem)
        <div class="alert alert-danger">{{ $problem }}</div>
    @elseif ($check)
        {{-- What needs attention: warnings, and how documents are signed when PDTS could not tell. --}}
        @if ($check['warnings'] || $check['tips'] || $signingBlocker || $template->isUnsigned())
        <section class="bg-white border rounded p-4 mb-4" data-test="template-check">
            @foreach ($check['warnings'] as $warning)
                <div class="alert alert-warning small py-2 mb-2"><i class="fas fa-exclamation-triangle mr-1"></i>{{ $warning }}</div>
            @endforeach
            @foreach ($check['tips'] as $tip)
                <div class="small text-muted mb-1"><i class="far fa-lightbulb mr-1"></i>{{ $tip }}</div>
            @endforeach
            @if ($signingBlocker)
                <div class="border rounded p-3 mt-2 mb-2" style="background: #fff8e6; border-color: #f0d9a0 !important;" data-test="signing-choice">
                    <div class="font-weight-bold small mb-1"><i class="fas fa-signature mr-1"></i>How are documents from this template signed?</div>
                    <div class="small text-muted mb-2">Authors are not offered this template until one of these is done.</div>
                    <div class="d-flex flex-wrap" style="gap: .5rem;">
                        @if ($canMark)
                            <a href="{{ route('pdts.organization.document-templates.qr', $template) }}" class="btn btn-sm btn-outline-primary"><i class="fas fa-highlighter mr-1"></i>They are signed: mark the signature line</a>
                        @else
                            <span class="small text-muted align-self-center">Documents were already made from this version: replace the file (a new version) to mark the signature line.</span>
                        @endif
                        <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="setUnsigned(true)" wire:confirm="Documents from {{ $template->name }} will be issued in PDTS without anyone's signature. Use this only for forms, notices or cover sheets that are never signed. Continue?" data-test="mark-unsigned">
                            <i class="fas fa-file-alt mr-1"></i>They are not signed (forms, notices)
                        </button>
                    </div>
                </div>
            @elseif ($template->isUnsigned())
                <div class="small text-muted mt-2" data-test="unsigned-note">
                    <i class="fas fa-info-circle mr-1"></i>Marked as not signed: the author (or the officer it was prepared for) issues each document in PDTS; nothing is printed or scanned.
                    <button type="button" class="btn btn-link btn-sm p-0 align-baseline" wire:click="setUnsigned(false)">Undo</button>
                </div>
            @endif
        </section>
        @endif

        {{-- 1. Form settings (uploaded templates; a built template's form is set in the builder) --}}
        @if (! $template->isBuilt() && $fieldNames)
            <section class="bg-white border rounded p-4 mb-4" data-test="field-settings">
                <h2 class="h5 mb-1">1. How the form looks</h2>
                <p class="text-muted small mb-3">The boxes authors fill in on New Internal Document, in the order of the document. An empty label keeps the wording PDTS found.</p>
                <form wire:submit.prevent="saveSettings">
                    <div class="table-responsive">
                        <table class="table table-sm tr-table mb-3">
                            <colgroup><col style="width: 22%"><col style="width: 20%"><col style="width: 12%"><col style="width: 18%"><col style="width: 22%"><col style="width: 6%"></colgroup>
                            <thead><tr><th>Label on the form</th><th>Type</th><th>Required</th><th>Filled with</th><th>Help text</th><th class="text-center">Hide</th></tr></thead>
                            <tbody>
                                @foreach ($fieldNames as $name)
                                    @php
                                        $heading = in_array($name, \App\Models\PdtsDocumentTemplate::HEADING_FIELDS, true);
                                    @endphp
                                    <tr wire:key="setting-{{ $name }}">
                                        <td><input type="text" class="form-control form-control-sm" maxlength="80" wire:model="settings.{{ $name }}.label" placeholder="{{ $foundLabels[$name] ?? '' }}"></td>
                                        <td>
                                            @if ($heading)
                                                <span class="small text-muted">{{ $name === 'date' ? 'Date' : ($name === 'cc' ? 'Long text' : 'Text') }}</span>
                                            @elseif (($settings[$name]['type'] ?? '') === 'table')
                                                {{-- From a grid in the file: the author adds rows; the columns are the grid's. --}}
                                                <span class="small text-muted" data-test="table-columns-{{ $name }}">Table: {{ implode(', ', $settings[$name]['columns'] ?? []) }}</span>
                                            @else
                                                <select class="form-control form-control-sm" wire:model.live="settings.{{ $name }}.type">
                                                    @foreach (\App\Models\PdtsDocumentTemplate::FIELD_TYPES as $value => $text)
                                                        <option value="{{ $value }}">{{ $text }}</option>
                                                    @endforeach
                                                </select>
                                                @if (($settings[$name]['type'] ?? '') === 'choice')
                                                    <input type="text" class="form-control form-control-sm mt-1" wire:model="settings.{{ $name }}.choices" placeholder="Choices, separated by commas">
                                                    @error('settings.'.$name.'.choices') <div class="text-danger small">{{ $message }}</div> @enderror
                                                @endif
                                            @endif
                                        </td>
                                        <td>
                                            <select class="form-control form-control-sm" wire:model="settings.{{ $name }}.required">
                                                <option value="">Default</option>
                                                <option value="1">Required</option>
                                                <option value="0">Optional</option>
                                            </select>
                                        </td>
                                        <td>
                                            @if ($heading)
                                                <span class="small text-muted">{{ ['from' => 'Signatory\'s title', 'date' => 'Today'][$name] ?? 'Author types it' }}</span>
                                            @else
                                                <select class="form-control form-control-sm" wire:model="settings.{{ $name }}.auto">
                                                    @foreach (\App\Models\PdtsDocumentTemplate::AUTO_FILL as $value => $text)
                                                        <option value="{{ $value }}">{{ $text }}</option>
                                                    @endforeach
                                                </select>
                                            @endif
                                        </td>
                                        <td><input type="text" class="form-control form-control-sm" maxlength="200" wire:model="settings.{{ $name }}.help" placeholder="Optional" title="Shown under the box on the form"></td>
                                        <td class="text-center"><input type="checkbox" wire:model="settings.{{ $name }}.hidden" aria-label="Hide {{ $foundLabels[$name] ?? $name }}"></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="small text-muted mb-2">Hidden: left empty on the document. A subject printed on the document is always asked for; without a subject line, PDTS names the document itself.</div>
                    <button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled" wire:target="saveSettings"><i class="fas fa-save mr-1"></i>Save form settings</button>
                </form>
            </section>
        @endif

        @php
            $formNumber = ! $template->isBuilt() && $fieldNames ? 2 : 1;
        @endphp

        {{-- The form authors fill in: the real form, in preview (nothing is saved). --}}
        @if (! $template->isPdf() && auth()->user()->can('document_types.manage'))
            <section class="bg-white border rounded p-4 mb-4" data-test="form-preview" x-data="{ open: false }">
                <h2 class="h5 mb-1">{{ $formNumber }}. The form authors fill in</h2>
                <p class="text-muted small mb-3">Exactly what an author sees on New Internal Document for this template: the questions, required answers, help and filled-in values. Nothing is saved.</p>
                <button type="button" class="btn btn-outline-primary btn-sm" x-show="! open" x-on:click="open = true" data-test="show-form"><i class="fas fa-edit mr-1"></i>Preview the form</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" x-show="open" x-cloak x-on:click="open = false" data-test="hide-form"><i class="fas fa-eye-slash mr-1"></i>Hide the form</button>
                <div x-show="open" x-cloak class="mt-3 border rounded p-3" style="background: #fcfdfd;">
                    <livewire:pdts.documents.internal-document :preview-template-id="$template->id" :key="'form-preview-'.$template->id.'-'.$template->updated_at?->timestamp" />
                </div>
            </section>
        @endif

        {{-- Try it --}}
        <section class="bg-white border rounded p-4 mb-4" data-test="try-it">
            <h2 class="h5 mb-1">{{ $template->isPdf() ? $formNumber : $formNumber + 1 }}. Try it</h2>
            @if ($canTry)
                <p class="text-muted small mb-3">The template filled with sample details and a sample signature, exactly as a document from it would be.</p>
                <div x-data="pdtsTryIt(@js(route('pdts.organization.document-templates.sample-pdf', $template)), @js(asset('vendor/pdfjs/pdf.worker.min.js')))">
                    <button type="button" class="btn btn-outline-primary btn-sm" x-on:click="show()" x-show="! open" :disabled="loading" data-test="show-sample">
                        <span x-show="! loading"><i class="fas fa-eye mr-1"></i><span x-text="shown ? 'Show the sample' : 'Show it with sample details'">Show it with sample details</span></span>
                        <span x-show="loading"><i class="fas fa-circle-notch fa-spin mr-1"></i>Making the sample, a few seconds…</span>
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" x-on:click="hide()" x-show="open" x-cloak data-test="hide-sample">
                        <i class="fas fa-eye-slash mr-1"></i>Hide the sample
                    </button>
                    <div x-show="failed" class="small text-danger mt-2" x-text="failed"></div>
                    <div x-ref="pages" wire:ignore class="mt-3" x-show="open || loading" style="max-height: 80vh; overflow: auto;"></div>
                    <button type="button" class="btn btn-outline-secondary btn-sm mt-2" x-on:click="hide(); $el.closest('section').scrollIntoView({ behavior: 'smooth', block: 'start' })" x-show="open" x-cloak>
                        <i class="fas fa-eye-slash mr-1"></i>Hide the sample
                    </button>
                </div>
            @elseif ($template->isPdf())
                <p class="text-muted small mb-0">PDF templates are printed as they are, with the QR code added. Use "Download a sample" on the QR code page to see it.</p>
            @else
                <p class="text-muted small mb-0">This server cannot make PDFs yet (LibreOffice is not installed). Use "Download a sample" on the QR code page to see the Word version.</p>
            @endif
        </section>
    @endif
</div>
