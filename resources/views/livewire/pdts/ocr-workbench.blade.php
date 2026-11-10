<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start mb-4">
        <div>
            <h1 class="h3 mb-1">Document OCR Review</h1>
            <p class="text-muted mb-0">Extracted information is a draft and requires human verification.</p>
        </div>
        <span class="badge badge-info">AI-assisted</span>
    </div>

    @if (!$analysis)
        <section class="bg-white border rounded p-4 mb-4">
            <form wire:submit.prevent="analyze" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="ocr-document">Scanned document</label>
                    <input
                        id="ocr-document"
                        type="file"
                        class="form-control-file"
                        accept=".pdf,.png,.jpg,.jpeg,.tif,.tiff"
                        wire:model="document">
                    <small class="form-text text-muted">PDF, PNG, JPEG, or TIFF. Maximum 20 MB.</small>
                    @error('document') <div class="text-danger mt-2">{{ $message }}</div> @enderror
                </div>

                <div wire:loading wire:target="document" class="text-muted mb-2">Preparing upload...</div>
                <button type="button" class="btn btn-primary" wire:click="analyze" wire:loading.attr="disabled" wire:target="analyze">
                    <span wire:loading.remove wire:target="analyze">Analyze document</span>
                    <span wire:loading wire:target="analyze">Reading document...</span>
                </button>
            </form>
        </section>
    @else
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
            <div>
                <h2 class="h5 mb-1">{{ $uploadedFilename }}</h2>
                <span class="text-muted">{{ $analysis['page_count'] ?? 0 }} page(s)</span>
            </div>
            <button type="button" class="btn btn-outline-secondary" wire:click="clearResults">Analyze another</button>
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
                        <dt>Dates</dt>
                        <dd class="mb-0">
                            @forelse(($analysis['suggestions']['dates'] ?? []) as $date)
                                <span class="badge badge-light mr-1 mb-1">{{ $date }}</span>
                            @empty
                                Not detected
                            @endforelse
                        </dd>
                    </dl>
                </section>
            </div>

            <div class="col-lg-8 mb-3">
                <section class="bg-white border rounded p-3 h-100">
                    <h2 class="h6 mb-3">Extracted text</h2>
                    <pre class="mb-0 text-wrap" style="white-space: pre-wrap; overflow-wrap: anywhere; max-height: 32rem; overflow-y: auto;">{{ $analysis['text'] ?? '' }}</pre>
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
    @endif
</div>
