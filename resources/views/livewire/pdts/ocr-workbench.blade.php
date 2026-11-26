<div class="container-fluid py-4"
    x-data="{ uploading: false, uploadFailed: false }"
    x-on:livewire-upload-start="uploading = true; uploadFailed = false"
    x-on:livewire-upload-finish="uploading = false"
    x-on:livewire-upload-error="uploading = false; uploadFailed = true"
    x-on:livewire-upload-cancel="uploading = false; uploadFailed = true">
    <div class="d-flex flex-wrap justify-content-between align-items-start mb-4">
        <div>
            <h1 class="h3 mb-1">Document OCR Review</h1>
            <p class="text-muted mb-0">Extracted information is a draft and requires human verification.</p>
        </div>
        <span class="badge badge-info">Local OCR</span>
    </div>

        <section class="bg-white border rounded p-4 mb-4">
            <form wire:submit.prevent="analyze" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="ocr-document">Scanned document</label>
                    <input
                        id="ocr-document"
                        type="file"
                        class="form-control-file"
                        accept=".pdf,.png,.jpg,.jpeg,.tif,.tiff"
                        wire:loading.attr="disabled" wire:target="analyze,clearResults,formatText"
                        wire:model="document">
                    <small class="form-text text-muted">PDF, PNG, JPEG, or TIFF. Maximum 20 MB.</small>
                    @error('document') <div class="text-danger mt-2" role="alert">{{ $message }}</div> @enderror
                </div>

                <div x-show="uploading" style="display: none;" class="text-muted mb-2" role="status">Preparing upload...</div>
                <div x-show="uploadFailed" style="display: none;" class="text-danger mb-2" role="alert">Upload did not complete. Please select the scan again.</div>
                <button type="button" class="btn btn-outline-primary" wire:click="analyze" wire:loading.attr="disabled" wire:target="analyze,document,clearResults,formatText"
                        x-bind:disabled="uploading || uploadFailed || !$wire.document" @disabled(! $document)>
                    <span wire:loading.remove wire:target="analyze"><i class="fas fa-file-alt mr-1" aria-hidden="true"></i>Analyse Document</span>
                    <span wire:loading wire:target="analyze"><i class="fas fa-spinner fa-spin mr-1" aria-hidden="true"></i>Extracting text...</span>
                </button>
                <span wire:loading wire:target="analyze" class="text-muted ml-2" role="status">Analysis in progress.</span>
                @if ($analysisError)
                    <div class="alert alert-warning mt-3 mb-0" role="alert" x-show="!uploading && !uploadFailed">{{ $analysisError }}</div>
                @endif
            </form>
        </section>

    @if ($analysis)
        <div x-show="!uploading && !uploadFailed" wire:loading.remove wire:target="analyze">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
            <div class="text-break">
                <h2 class="h5 mb-1 text-break">{{ $uploadedFilename }}</h2>
                <span class="small text-success" role="status">Analysis complete. {{ $analysis['page_count'] }} {{ $analysis['page_count'] === 1 ? 'page' : 'pages' }} processed.</span>
            </div>
            <button type="button" class="btn btn-outline-secondary mt-2" wire:click="clearResults"
                    wire:loading.attr="disabled" wire:target="analyze,document,clearResults,formatText">
                <i class="fas fa-redo mr-1" aria-hidden="true"></i>Analyse another
            </button>
        </div>

        <div class="alert alert-warning" role="alert">
            Review all extracted values against the original before using them to register a document.
        </div>

        <div class="row">
            <div class="col-lg-4 mb-3">
                <section class="bg-white border rounded p-3 h-100">
                    <h2 class="h6 mb-3">Suggested metadata</h2>
                    <dl class="mb-0">
                        <dt>Reference number</dt>
                        <dd>{{ $analysis['suggestions']['reference_number'] ?? 'Not detected' }}</dd>
                        <dt>Subject</dt>
                        <dd>{{ $analysis['suggestions']['suggested_subject'] ?? 'Not detected' }}</dd>
                        <dt>Document date</dt>
                        <dd class="mb-0">
                            @forelse(($analysis['suggestions']['dates'] ?? []) as $date)
                                <span class="badge badge-light mr-1 mb-1">{{ $date }}</span>
                            @empty
                                Not clearly detected
                            @endforelse
                        </dd>
                    </dl>
                </section>
            </div>

            <div class="col-lg-8 mb-3">
                <section class="bg-white border rounded p-3 h-100">
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                        <h2 class="h6 mb-2">Document text</h2>
                        @if (! $formattedBlocks)
                            <button type="button" class="btn btn-sm btn-outline-primary mb-2"
                                    wire:click="formatText" wire:loading.attr="disabled"
                                    wire:target="formatText,analyze,document,clearResults"
                                    title="Format extracted text with AI">
                                <span wire:loading.remove wire:target="formatText"><i class="fas fa-align-left mr-1" aria-hidden="true"></i>Format with AI</span>
                                <span wire:loading wire:target="formatText" role="status"><i class="fas fa-spinner fa-spin mr-1" aria-hidden="true"></i>Organising text...</span>
                            </button>
                        @else
                            <span class="badge badge-light mb-2">AI-formatted draft</span>
                        @endif
                    </div>
                    @if ($formattingError)
                        <div class="alert alert-warning" role="alert">{{ $formattingError }}</div>
                    @endif

                    @if ($formattedBlocks)
                        <div x-data="{ textView: 'formatted' }" wire:key="formatted-{{ hash('sha256', $analysis['text']) }}">
                            <div class="nav nav-tabs mb-3" role="tablist" aria-label="Document text view"
                                 x-on:keydown.right.prevent="textView = textView === 'formatted' ? 'original' : 'formatted'; $nextTick(() => $el.querySelector('[aria-selected=true]').focus())"
                                 x-on:keydown.left.prevent="textView = textView === 'formatted' ? 'original' : 'formatted'; $nextTick(() => $el.querySelector('[aria-selected=true]').focus())">
                                <button type="button" id="ocr-formatted-tab" class="nav-link" role="tab" aria-controls="ocr-formatted-panel"
                                        x-bind:class="{ 'active': textView === 'formatted' }" x-bind:aria-selected="textView === 'formatted'"
                                        x-bind:tabindex="textView === 'formatted' ? 0 : -1" x-on:click="textView = 'formatted'">Formatted</button>
                                <button type="button" id="ocr-original-tab" class="nav-link" role="tab" aria-controls="ocr-original-panel"
                                        x-bind:class="{ 'active': textView === 'original' }" x-bind:aria-selected="textView === 'original'"
                                        x-bind:tabindex="textView === 'original' ? 0 : -1" x-on:click="textView = 'original'">Original</button>
                            </div>
                            <div id="ocr-formatted-panel" role="tabpanel" aria-labelledby="ocr-formatted-tab" tabindex="0"
                                 x-show="textView === 'formatted'" style="max-height: 70vh; min-height: 18rem; overflow-y: auto; overflow-wrap: anywhere; line-height: 1.75;">
                                @foreach ($formattedBlocks as $block)
                                    @switch($block['type'])
                                        @case('heading')
                                            <h3 class="h6 font-weight-bold mt-4 mb-3">{{ $block['text'] }}</h3>
                                            @break
                                        @case('paragraph')
                                            <p class="mb-3" style="white-space: pre-line;">{{ $block['text'] }}</p>
                                            @break
                                        @case('list')
                                            <ul class="pl-4 mb-3">
                                                @foreach ($block['items'] as $item)
                                                    <li class="mb-1">{{ $item }}</li>
                                                @endforeach
                                            </ul>
                                            @break
                                        @case('table')
                                            <div class="table-responsive mb-3">
                                                <table class="table table-sm table-bordered mb-0" style="line-height: 1.5;">
                                                    <thead>
                                                        <tr>
                                                            @foreach ($block['headers'] as $header)
                                                                <th scope="col" class="align-top text-left" style="min-width: 7rem; white-space: normal;">{{ $header }}</th>
                                                            @endforeach
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @foreach ($block['rows'] as $row)
                                                            <tr>
                                                                @foreach ($row as $cell)
                                                                    <td class="align-top text-left" style="white-space: normal;">{{ $cell }}</td>
                                                                @endforeach
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                            @break
                                    @endswitch
                                @endforeach
                            </div>
                            <div id="ocr-original-panel" role="tabpanel" aria-labelledby="ocr-original-tab" tabindex="0"
                                 x-show="textView === 'original'" style="display: none;">
                                <textarea class="form-control" rows="16" readonly aria-label="Original extracted text">{{ $analysis['text'] }}</textarea>
                            </div>
                        </div>
                    @else
                        <textarea class="form-control" rows="12" readonly aria-label="Original extracted text">{{ $analysis['text'] }}</textarea>
                    @endif
                </section>
            </div>
        </div>

        <section class="bg-white border rounded p-3">
            <h2 class="h6 mb-3">Page analysis</h2>
            <div class="table-responsive">
                <table class="table table-sm table-striped mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Page</th>
                            <th scope="col">Text source</th>
                            <th scope="col">OCR confidence</th>
                            <th scope="col">Extracted characters</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach(($analysis['pages'] ?? []) as $page)
                            <tr>
                                <td>{{ $page['page'] ?? '' }}</td>
                                <td>{{ ($page['source'] ?? '') === 'ocr' ? 'OCR' : 'Embedded PDF text' }}</td>
                                <td>{{ isset($page['confidence']) ? number_format($page['confidence'] * 100, 1).'%' : 'N/A' }}</td>
                                <td>{{ mb_strlen($page['text'] ?? '') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
        </div>
    @endif
</div>
