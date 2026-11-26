@php
    $status = $document->statusEnum();
    $statusTone = match ($document->status) {
        'completed' => ['#e8f5ec', '#218838', 'fa-check-double'],
        'received' => ['#e7f1ff', '#1f5fbf', 'fa-check-circle'],
        'awaiting_receipt' => ['#fff4e0', '#b86e00', 'fa-shipping-fast'],
        'draft' => ['#f1f3f5', '#495057', 'fa-pencil-alt'],
        default => ['#eef0f3', '#4a5563', 'fa-file-alt'],
    };
    $pending = $document->pendingMovement;

    $activity = [
        'draft_created' => ['Draft created from template', 'fa-file-word', '#6c757d'],
        'signed_electronically' => ['Signed electronically', 'fa-signature', '#075B35'],
        'sent_for_signature' => ['Sent for signature', 'fa-paper-plane', '#2f6fdf'],
        'returned_for_correction' => ['Returned for correction', 'fa-undo', '#e8890c'],
        'draft_edited' => ['Draft edited', 'fa-pen', '#6c757d'],
        'draft_discarded' => ['Draft discarded', 'fa-ban', '#6c757d'],
        'distributed' => ['Sent to the people it is for (in PDTS)', 'fa-envelope-open-text', '#2f6fdf'],
        'document_cancelled' => ['Cancelled', 'fa-ban', '#6c757d'],
        'reported_found' => ['Reported found by someone not involved', 'fa-flag', '#dc3545'],
        'cover_sheet_printed' => ['Cover sheet printed', 'fa-qrcode', '#00873C'],
        'registered' => ['Registered', 'fa-plus', '#2f6fdf'],
        'handover_forwarded' => ['Forwarded', 'fa-share', '#e8890c'],
        'handover_acknowledged' => ['Received', 'fa-check', '#28a745'],
        'handover_declined' => ['Declined', 'fa-times', '#dc3545'],
        'handover_cancelled' => ['Handover cancelled', 'fa-ban', '#6c757d'],
        'handover_returned' => ['Sent back', 'fa-undo', '#e8890c'],
        'document_completed' => ['Completed', 'fa-check-double', '#218838'],
        'handover_picked_up' => ['Picked up', 'fa-truck', '#17a2b8'],
        'handover_delivered' => ['Delivered', 'fa-inbox', '#17a2b8'],
    ];

    $fileIcon = fn ($attachment) => match (true) {
        str_contains((string) $attachment->mime_type, 'pdf') => ['fa-file-pdf', '#d64545'],
        str_starts_with((string) $attachment->mime_type, 'image/') => ['fa-file-image', '#2f6fdf'],
        default => ['fa-file-alt', '#6c757d'],
    };
    $fileSize = fn (int $bytes) => $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : number_format($bytes / 1024, 1).' KB';
@endphp

<div class="doc-show" style="padding: 0 .75rem 90px;">
    @assets
        <script src="{{ asset('vendor/jszip/jszip.min.js') }}"></script>
        <script src="{{ asset('vendor/docx-preview/docx-preview.min.js') }}"></script>
        <script src="{{ asset('vendor/pdfjs/pdf.min.js') }}"></script>
        <script>
            // Shows the draft on the page, so the signatory reads exactly what they sign:
            // the PDF it will become (pdfUrl), or, where the server cannot make PDFs, the Word file.
            window.pdtsDraftPreview = (wordUrl, pdfUrl, workerSrc) => ({
                open: false, loading: false, failed: false, done: false,
                async toggle() {
                    this.open = ! this.open;
                    if (! this.open || this.done) return;
                    this.loading = true;
                    try {
                        pdfUrl ? await this.showPdf() : await this.showWord();
                        this.done = true;
                    } catch (e) {
                        this.failed = true;
                    }
                    this.loading = false;
                },
                async showPdf() {
                    window.pdfjsLib.GlobalWorkerOptions.workerSrc = workerSrc;
                    const pdf = await window.pdfjsLib.getDocument({ url: pdfUrl, withCredentials: true }).promise;
                    const host = this.$refs.pages;
                    const width = Math.max(320, host.clientWidth - 24);
                    for (let n = 1; n <= pdf.numPages; n++) {
                        const page = await pdf.getPage(n);
                        const viewport = page.getViewport({ scale: width / page.getViewport({ scale: 1 }).width });
                        const ratio = window.devicePixelRatio || 1;
                        const canvas = document.createElement('canvas');
                        canvas.width = viewport.width * ratio;
                        canvas.height = viewport.height * ratio;
                        canvas.style.width = viewport.width + 'px';
                        canvas.style.height = viewport.height + 'px';
                        canvas.style.display = 'block';
                        canvas.style.margin = '0 auto 12px';
                        canvas.style.boxShadow = '0 1px 6px rgba(0,0,0,.15)';
                        host.appendChild(canvas);
                        await page.render({ canvasContext: canvas.getContext('2d'), viewport, transform: ratio !== 1 ? [ratio, 0, 0, ratio, 0, 0] : null }).promise;
                    }
                },
                async showWord() {
                    const response = await fetch(wordUrl, { credentials: 'same-origin' });
                    if (! response.ok) throw new Error(response.status);
                    await window.docx.renderAsync(await response.arrayBuffer(), this.$refs.pages, null, { inWrapper: true, breakPages: true, renderHeaders: true, renderFooters: true, useBase64URL: true });
                },
            });
        </script>
    @endassets
    <style>
        .doc-show .label { font-size: .75rem; letter-spacing: .04em; text-transform: uppercase; color: #6c757d; margin-bottom: .125rem; }
        .doc-show .value { font-weight: 500; overflow-wrap: anywhere; }
        .doc-show .ref { font-variant-numeric: tabular-nums; letter-spacing: .01em; }
        .doc-show .status-pill { display: inline-flex; align-items: center; gap: .375rem; padding: .25rem .75rem; border-radius: 999px; font-size: .8125rem; font-weight: 600; }
        .doc-show .custody { display: flex; align-items: center; gap: .875rem; padding: .875rem 1.25rem; border-top: 1px solid #eef0f3; border-radius: 0 0 .25rem .25rem; }
        .doc-show .custody-icon { width: 2.25rem; height: 2.25rem; border-radius: 50%; display: grid; place-items: center; flex-shrink: 0; }
        .doc-show .file-tile { width: 3rem; height: 3rem; border-radius: .5rem; display: grid; place-items: center; font-size: 1.5rem; flex-shrink: 0; background: #f4f6f9; }
        .doc-show .timeline { list-style: none; margin: 0; padding: 0; }
        .doc-show .timeline li { position: relative; display: flex; gap: 1rem; padding-bottom: 1.25rem; }
        .doc-show .timeline li:not(:last-child)::before { content: ""; position: absolute; left: 1rem; top: 2.25rem; bottom: .25rem; width: 2px; background: #e9ecef; }
        .doc-show .timeline .dot { width: 2rem; height: 2rem; border-radius: 50%; display: grid; place-items: center; color: #fff; font-size: .8125rem; flex-shrink: 0; }
        .doc-show .timeline .when { white-space: nowrap; text-align: right; font-size: .8125rem; color: #6c757d; }
        .doc-show .note { font-size: .875rem; color: #555; background: #f7f8fa; border-radius: .25rem; padding: .375rem .625rem; margin-top: .375rem; }
    </style>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    {{-- Header --}}
    <section class="card elevation-2 border-0 mb-3">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-start" style="gap: 1rem;">
            <div style="min-width: 0;">
                <a href="{{ route('pdts.documents.index') }}" class="text-muted small"><i class="fas fa-arrow-left mr-1"></i>Documents</a>
                <h1 class="h3 font-weight-bold mt-2 mb-1 ref">{{ $document->reference_number }}</h1>
                <p class="mb-2 text-muted" style="font-size: 1.0625rem;">{{ $document->title }}</p>
                <div class="d-flex flex-wrap align-items-center" style="gap: .5rem;">
                    @php
                        // A draft says plainly what it is waiting for (the shared status wording is the tracking pages').
                        $statusText = ! $document->isDraft() ? $status->plainLabel() : match (true) {
                            $isDiscarded => 'Draft discarded',
                            $onBehalf && $document->signature_status === 'returned' => 'Draft: returned for correction',
                            $onBehalf && $document->signature_status !== 'awaiting_signature' => 'Draft: with the preparer',
                            $isUnsigned => 'Draft: waiting to be issued',
                            default => 'Draft: waiting for signature',
                        };
                        // Not sent yet: who has it and since when goes in the label, not in a bar of its own.
                        $notSent = $document->status === 'registered' && ! $document->movements()->exists();
                        if ($notSent) {
                            $holder = (int) $document->current_custodian_id === (int) auth()->id() ? 'you' : ($document->currentCustodian?->name ?? 'a former user');
                            $statusText .= ' · with '.$holder.($heldSince ? ' since '.$heldSince->format($heldSince->isToday() ? 'H:i' : 'd M Y') : '');
                        }
                    @endphp
                    {{-- A discarded draft says so once, in the bar below. --}}
                    @unless ($isDiscarded)
                        <span class="status-pill" style="background: {{ $statusTone[0] }}; color: {{ $statusTone[1] }};" data-test="status-pill">
                            <i class="fas {{ $statusTone[2] }}"></i>{{ $statusText }}
                        </span>
                    @endunless
                    @if ($document->documentType)
                        <span class="badge badge-light border px-2 py-1">{{ $document->documentType->name }}</span>
                    @endif
                </div>
            </div>

            <div class="d-flex flex-wrap align-items-start" style="gap: .5rem;">
                @if ($canDownloadWord)
                    {{-- A quiet button: on a draft, signing is the main thing to do. --}}
                    <a href="{{ route('pdts.documents.word', $document) }}" class="btn btn-sm btn-outline-secondary">
                        @php
                            $isPdfTemplate = $document->template?->isPdf();
                        @endphp
                        <i class="fas {{ $isPdfTemplate ? 'fa-file-pdf' : 'fa-file-word' }} mr-1"></i>{{ $isPdfTemplate ? 'PDF' : 'Word file' }}
                    </a>
                @endif
                {{-- Only a registered paper document has a cover sheet: an internal one carries its QR code already. --}}
                @if ($canPrintCoverSheet)
                    <div class="btn-group">
                        <a href="{{ route('pdts.documents.cover-sheet', $document) }}" target="_blank" rel="noopener" class="btn btn-sm {{ $document->isInternal() ? 'btn-outline-secondary' : 'btn-outline-success' }}">
                            <i class="fas fa-qrcode mr-1"></i>{{ $coverSheetPrinted ? 'Reprint cover sheet' : 'Print cover sheet' }}
                        </a>
                        <button type="button" class="btn btn-sm {{ $document->isInternal() ? 'btn-outline-secondary' : 'btn-outline-success' }} dropdown-toggle dropdown-toggle-split" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            <span class="sr-only">More cover sheet options</span>
                        </button>
                        <div class="dropdown-menu dropdown-menu-right">
                            <a class="dropdown-item" href="{{ route('pdts.documents.cover-sheet', [$document, 'confidential' => 1]) }}" target="_blank" rel="noopener">
                                <i class="fas fa-user-secret fa-fw mr-2 text-muted"></i>Confidential version (QR and reference only)
                            </a>
                        </div>
                    </div>
                @endif
                @if ($canForward)
                    {{-- No "Forwarded to..." note here: the bar under the title says where it is going. --}}
                    <livewire:movements.forward-document :document="$document" :quiet="true" :key="'forward-'.$document->id" />
                @endif
                {{-- Where the paper has been: nothing to show until it has moved. --}}
                @if ($canViewHistory && ! $document->isDraft() && ! $notSent)
                    <a href="{{ route('movements.history', $document) }}" class="btn btn-sm btn-outline-primary"><i class="fas fa-route mr-1"></i>Movement history</a>
                @endif
                @if ($canReply)
                    <a href="{{ route('pdts.documents.internal.create', ['reply_to' => $document->id]) }}" class="btn btn-sm btn-outline-secondary"><i class="fas fa-reply mr-1"></i>Reply</a>
                @endif
            </div>
        </div>

        {{-- Who is responsible right now --}}
        @if ($document->isDraft())
            @php
                $statusLabels = [
                    'with_preparer' => ['With the preparer', 'badge-secondary'],
                    'awaiting_signature' => ['Waiting for signature', 'badge-primary'],
                    'returned' => ['Returned for correction', 'badge-warning'],
                ];
                $usesPin = auth()->user()->signature_secret_type !== 'password';
            @endphp
            <div class="custody" style="background: #f7f8fa; align-items: flex-start;">
                <span class="custody-icon" style="background: #e9ecef; color: #495057;"><i class="fas {{ $isDiscarded ? 'fa-ban' : 'fa-pencil-alt' }}"></i></span>
                <div class="flex-grow-1">
                    @if ($isDiscarded)
                        <div class="font-weight-bold">Draft discarded</div>
                        <div class="small text-muted">It will not be signed or sent. Its reference and history are kept on record.</div>
                        @php
                            $discarding = $document->auditLogs->where('action', 'draft_discarded')->sortByDesc('id')->first();
                        @endphp
                        @if ($discarding)
                            <div class="small text-muted mt-1" data-test="discarded-by">
                                Discarded by {{ $discarding->actor?->name ?? 'a former user' }} on {{ $discarding->occurred_at?->format('d M Y H:i') }}{{ ! empty($discarding->details['reason']) ? ': '.$discarding->details['reason'] : '' }}
                            </div>
                        @endif
                    @else
                        @if ($onBehalf)
                            <div class="small text-muted mb-2" data-test="prepared-on-behalf">
                                Prepared by {{ $document->registrar?->name }} for <strong>{{ $document->signatory?->name }}</strong> to review and sign.
                                @if (isset($statusLabels[$document->signature_status]))
                                    <span class="badge {{ $statusLabels[$document->signature_status][1] }} ml-1">{{ $statusLabels[$document->signature_status][0] }}</span>
                                @endif
                            </div>
                        @endif

                        @error('workflow') <div class="alert alert-danger small py-2">{{ $message }}</div> @enderror

                        @if ($preparerLostAccess)
                            <div class="alert alert-warning small py-2"><i class="fas fa-lock mr-1"></i>You are no longer authorised to prepare documents for {{ $document->signatory?->name }}, so you cannot edit or send this draft. {{ $document->signatory?->name }} can still sign or discard it.</div>
                        @endif

                        @if ($document->signature_status === 'returned' && $document->signature_note)
                            <div class="alert alert-warning small py-2" data-test="return-comment"><i class="fas fa-undo mr-1"></i><strong>{{ $document->signatory?->name }} returned it for correction:</strong> "{{ $document->signature_note }}"</div>
                        @endif

                        @if ($canEditDraft || $canSendForSignature)
                            <div class="d-flex flex-wrap mb-3" style="gap: .5rem;">
                                @if ($canEditDraft)
                                    <a href="{{ route('pdts.documents.internal.edit', $document) }}" class="btn btn-sm btn-outline-primary"><i class="fas fa-pen mr-1"></i>Edit draft</a>
                                @endif
                                @if ($canSendForSignature)
                                    <button type="button" class="btn btn-sm btn-primary" wire:click="sendForSignature" wire:confirm="Send {{ $document->reference_number }} to {{ $document->signatory?->name }} to review and sign?" wire:loading.attr="disabled" wire:target="sendForSignature">
                                        <i class="fas fa-paper-plane mr-1"></i>{{ $document->signature_status === 'returned' ? 'Send back' : 'Send' }} to {{ $document->signatory?->name }} {{ $isUnsigned ? 'to issue' : 'for signature' }}
                                    </button>
                                @endif
                            </div>
                        @endif

                        @if ($onBehalf && $document->signature_status === 'awaiting_signature' && (int) $document->signatoryId() !== (int) auth()->id())
                            <div class="small text-muted mb-3"><i class="fas fa-hourglass-half mr-1"></i>Waiting for {{ $document->signatory?->name }} to review and {{ $isUnsigned ? 'issue' : 'sign' }}. You will be told when it is {{ $isUnsigned ? 'issued' : 'signed' }} or returned.</div>
                        @endif

                        @if ($offerSigning)
                            <div class="border rounded p-3 mb-3" style="background: #f3faf6; border-color: #cfe4d9 !important;" data-test="sign-electronically">
                                <div class="font-weight-bold mb-1"><i class="fas fa-signature mr-1" style="color: #075B35;"></i>{{ $onBehalf ? 'Review and sign' : 'Sign electronically' }}</div>
                                <div class="small text-muted mb-2">Read it, tick the box and enter your PIN: PDTS signs and registers it.{{ $onBehalf ? ' '.$document->registrar?->name.' then sends it on.' : '' }}</div>

                                @if ($canDownloadWord)
                                    <div x-data="pdtsDraftPreview(@js(route('pdts.documents.word', [$document, 'preview' => 1])), @js($previewAsPdf ? route('pdts.documents.word', [$document, 'pdf' => 1]) : null), @js(asset('vendor/pdfjs/pdf.worker.min.js')))" class="mb-3">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="toggle()" x-text="open ? 'Hide the document' : 'Read the document here'"></button>
                                        @if ($previewAsPdf)
                                            <a href="{{ route('pdts.documents.word', [$document, 'pdf' => 1]) }}" class="btn btn-sm btn-link">Download the PDF</a>
                                        @endif
                                        <div x-show="open" x-cloak class="mt-2 border rounded bg-white" style="max-height: 70vh; overflow: auto;">
                                            <div x-show="loading" class="text-center text-muted py-4"><i class="fas fa-circle-notch fa-spin mr-1"></i>{{ $previewAsPdf ? 'Preparing the document exactly as it will be signed. The first time takes a few seconds…' : 'Opening the document…' }}</div>
                                            <div x-show="failed" class="small text-muted p-3">The document could not be shown here. Use Download it instead.</div>
                                            <div x-ref="pages" wire:ignore></div>
                                        </div>
                                    </div>
                                @endif

                                @if ($signingBlocker)
                                    <div class="small mb-0" style="color: #8a6100;">
                                        <i class="fas fa-info-circle mr-1"></i>{{ $signingBlocker }}
                                        @if (! auth()->user()->canSignElectronically())
                                            <a href="{{ route('settings.signature') }}" class="ml-1">Set up My Signature</a>
                                        @endif
                                    </div>
                                @else
                                    <form wire:submit.prevent="signElectronically" autocomplete="off">
                                        <div class="custom-control custom-checkbox mb-2">
                                            <input type="checkbox" class="custom-control-input" id="signature-reviewed" wire:model="reviewed">
                                            <label class="custom-control-label small" for="signature-reviewed">I have reviewed this document and approve its content.</label>
                                        </div>
                                        @error('reviewed') <div class="text-danger small mb-2">{{ $message }}</div> @enderror
                                        <div class="d-flex flex-wrap align-items-center" style="gap: .5rem;">
                                            <label for="signature-pin" class="sr-only">{{ $usesPin ? 'Signature PIN' : 'Signature password' }}</label>
                                            <input id="signature-pin" type="password" @if ($usesPin) inputmode="numeric" maxlength="8" @else maxlength="64" @endif class="form-control form-control-sm" style="max-width: 13rem;" placeholder="{{ $usesPin ? 'Signature PIN' : 'Signature password' }}" wire:model="signaturePin" autocomplete="off">
                                            <button type="submit" class="btn btn-sm text-white" style="background: #075B35;" wire:loading.attr="disabled" wire:target="signElectronically">
                                                <span wire:loading.remove wire:target="signElectronically"><i class="fas fa-pen-nib mr-1"></i>Apply my signature</span>
                                                <span wire:loading wire:target="signElectronically"><i class="fas fa-circle-notch fa-spin mr-1"></i>Signing…</span>
                                            </button>
                                        </div>
                                        @error('signaturePin') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                                    </form>
                                @endif

                                @if ($canReturn)
                                    <details class="mt-3" @error('returnComment') open @enderror>
                                        <summary class="small font-weight-bold" style="cursor: pointer;"><i class="fas fa-undo mr-1"></i>Return to {{ $document->registrar?->name }} for correction</summary>
                                        <form wire:submit.prevent="returnForCorrection" class="mt-2">
                                            <textarea class="form-control form-control-sm mb-2" rows="3" maxlength="2000" wire:model="returnComment" placeholder="Say what needs to be changed, e.g. Correct the amount in paragraph 2."></textarea>
                                            @error('returnComment') <div class="text-danger small mb-2">{{ $message }}</div> @enderror
                                            <button type="submit" class="btn btn-sm btn-outline-warning" wire:loading.attr="disabled" wire:target="returnForCorrection">Return for correction</button>
                                        </form>
                                    </details>
                                @endif
                            </div>
                        @endif

                        @if ($offerIssue)
                            <div class="border rounded p-3 mb-3" style="background: #f3faf6; border-color: #cfe4d9 !important;" data-test="issue-unsigned">
                                <div class="font-weight-bold mb-1"><i class="fas fa-file-export mr-1" style="color: #075B35;"></i>Issue in PDTS</div>
                                <div class="small text-muted mb-2">Not signed: read it, tick the box and issue it. PDTS registers it.{{ $onBehalf ? ' '.$document->registrar?->name.' then sends it on.' : '' }}</div>
                                {{-- Read on the page, as before signing: no need to download it just to check it. --}}
                                @if ($canDownloadWord)
                                    <div x-data="pdtsDraftPreview(@js(route('pdts.documents.word', [$document, 'preview' => 1])), @js($previewAsPdf ? route('pdts.documents.word', [$document, 'pdf' => 1]) : null), @js(asset('vendor/pdfjs/pdf.worker.min.js')))" class="mb-3" data-test="issue-read">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" x-on:click="toggle()" x-text="open ? 'Hide the document' : 'Read the document here'"></button>
                                        @if ($previewAsPdf)
                                            <a href="{{ route('pdts.documents.word', [$document, 'pdf' => 1]) }}" class="btn btn-sm btn-link">Download the PDF</a>
                                        @endif
                                        <div x-show="open" x-cloak class="mt-2 border rounded bg-white" style="max-height: 70vh; overflow: auto;">
                                            <div x-show="loading" class="text-center text-muted py-4"><i class="fas fa-circle-notch fa-spin mr-1"></i>{{ $previewAsPdf ? 'Preparing the document exactly as it will be issued. The first time takes a few seconds…' : 'Opening the document…' }}</div>
                                            <div x-show="failed" class="small text-muted p-3">The document could not be shown here. Use the Word file button at the top instead.</div>
                                            <div x-ref="pages" wire:ignore></div>
                                        </div>
                                    </div>
                                @endif
                                <form wire:submit.prevent="issueUnsigned">
                                    <div class="custom-control custom-checkbox mb-2">
                                        <input type="checkbox" class="custom-control-input" id="issue-reviewed" wire:model="reviewed">
                                        <label class="custom-control-label small" for="issue-reviewed">I have reviewed this document and approve its content.</label>
                                    </div>
                                    @error('reviewed') <div class="text-danger small mb-2">{{ $message }}</div> @enderror
                                    <button type="submit" class="btn btn-sm text-white" style="background: #075B35;" wire:loading.attr="disabled" wire:target="issueUnsigned">
                                        <span wire:loading.remove wire:target="issueUnsigned"><i class="fas fa-check mr-1"></i>Issue the document</span>
                                        <span wire:loading wire:target="issueUnsigned"><i class="fas fa-circle-notch fa-spin mr-1"></i>Issuing…</span>
                                    </button>
                                </form>
                            </div>
                        @endif

                        @if ($canUploadScan)
                            {{-- Folded away when the document can be signed or issued in PDTS; open when paper is the only way. --}}
                            @php $paperOnly = ! $offerSigning && ! $offerIssue && ! $onBehalf; @endphp
                            <details data-test="sign-on-paper" @if ($paperOnly || $errors->has('signedScan')) open @endif>
                            <summary class="small font-weight-bold text-muted mb-1" style="cursor: pointer;">{{ $paperOnly ? 'Sign on paper' : 'Sign on paper instead' }}</summary>
                            <ol class="small text-muted pl-3 mb-2">
                                <li>
                                    {{ $document->template?->isPdf() ? 'Download the PDF. The QR code is already on it.' : 'Download the Word file. The QR code and reference are already on it.' }}
                                    @if ($canDownloadWord)
                                        <a href="{{ route('pdts.documents.word', $document) }}">{{ $document->template?->isPdf() ? 'Download the PDF' : 'Download the Word file' }}</a>
                                    @endif
                                </li>
                                <li>{{ $document->template?->isPdf() ? 'Print it, fill it in and sign it.' : ($onBehalf ? 'Print it and have '.$document->signatory?->name.' sign it.' : 'Complete the content if needed, print it and sign it.') }}</li>
                                <li>Scan the signed paper and upload it here. The document can be sent once it is registered.</li>
                            </ol>
                            <form wire:submit.prevent="uploadSignedScan" class="d-flex flex-wrap align-items-center" style="gap: .5rem;">
                                <input type="file" class="form-control-file" style="max-width: 320px;" wire:model="signedScan" accept=".pdf,.png,.jpg,.jpeg,.tif,.tiff" aria-label="Scan of the signed document">
                                <button type="submit" class="btn btn-sm btn-success" wire:loading.attr="disabled" wire:target="signedScan,uploadSignedScan">
                                    <i class="fas fa-upload mr-1"></i>Upload signed scan
                                </button>
                                <span wire:loading wire:target="signedScan" class="small text-muted">Preparing upload...</span>
                            </form>
                            @error('signedScan') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            </details>
                        @endif

                        @if ($canDiscard)
                            <details class="mt-3">
                                <summary class="small text-muted" style="cursor: pointer;">Discard this draft</summary>
                                <form wire:submit.prevent="discardDraft" class="d-flex flex-wrap align-items-center mt-2" style="gap: .5rem;">
                                    <input type="text" class="form-control form-control-sm" style="max-width: 22rem;" maxlength="500" wire:model="discardReason" placeholder="Reason (optional)">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" wire:confirm="Discard {{ $document->reference_number }}? It will not be signed or sent; the record is kept.">Discard draft</button>
                                </form>
                            </details>
                        @endif
                    @endif
                </div>
            </div>
        @elseif ($pending)
            <div class="custody" style="background: #fff8ec;">
                <span class="custody-icon" style="background: #ffe7c2; color: #b86e00;"><i class="fas fa-shipping-fast"></i></span>
                <div>
                    <div class="font-weight-bold">On its way to {{ $pending->recipient?->name ?? 'a former user' }}</div>
                    <div class="small text-muted">
                        Sent {{ $pending->forwarded_at?->diffForHumans() }}.
                        @if ($pending->messenger_id)
                            Messenger: {{ $pending->messenger?->name }}. {{ $pending->delivered_at ? 'Delivered; awaiting acknowledgement.' : ($pending->picked_up_at ? 'Picked up; not delivered.' : 'Awaiting pickup.') }}
                        @endif
                        {{ (int) $document->current_custodian_id === (int) auth()->id() ? 'You stay' : ($document->currentCustodian?->name ?? 'The sender').' stays' }} responsible until it is acknowledged.
                    </div>
                </div>
            </div>
        @elseif (! $notSent)
            <div class="custody" style="background: #f4f8ff;">
                <span class="custody-icon" style="background: #dce9ff; color: #1f5fbf;"><i class="fas fa-user-check"></i></span>
                <div>
                    <div class="font-weight-bold">With {{ $document->currentCustodian?->name ?? 'a former user' }}</div>
                    @if ($heldSince)
                        <div class="small text-muted">Since {{ $heldSince->format('d M Y H:i') }} ({{ $heldSince->diffForHumans(null, true) }})</div>
                    @endif
                </div>
            </div>
        @endif
    </section>

    <div class="row">
        {{-- Closed: cancelled, filed --}}
        @if ($document->status === 'cancelled' || $document->completed_at)
            <div class="col-12 mb-3" data-test="closing">
                @if ($document->status === 'cancelled')
                    <div class="alert alert-secondary mb-2"><i class="fas fa-ban mr-1"></i><strong>Cancelled</strong> by {{ $document->canceller?->name ?? 'an administrator' }} on {{ $document->cancelled_at?->format('j M Y') }}: {{ $document->cancel_reason }}</div>
                @endif
                @if ($document->completed_at)
                    <div class="alert alert-light border mb-2 small">
                        <i class="fas fa-archive mr-1"></i>Completed {{ $document->completed_at->format('j M Y') }}@if ($document->file_reference) · filed at <strong>{{ $document->file_reference }}</strong>@endif
                        @if ($until = $document->retainUntil()) · kept until {{ $until->format('j M Y') }} @endif
                    </div>
                @endif
            </div>
        @endif

        {{-- Who it is for, and copies (not for a discarded draft: it goes to no one) --}}
        @if (! $isDiscarded && ($document->recipientUser || $copies->isNotEmpty()))
            <div class="col-12 mb-3">
                <section class="card elevation-2 border-0" data-test="distribution">
                    <div class="card-body">
                        <div class="d-flex flex-wrap justify-content-between align-items-start" style="gap: 1rem;">
                            <div>
                                <h2 class="h6 font-weight-bold text-uppercase text-muted mb-2" style="letter-spacing: .04em;">Who it is for</h2>
                                @if ($document->recipientUser)
                                    <div><strong>{{ $document->recipientUser->name }}</strong>@if ($document->recipientUser->job_title)<span class="text-muted"> · {{ $document->recipientUser->job_title }}</span>@endif</div>
                                @endif
                                @if ($copies->isNotEmpty())
                                    <div class="small text-muted mt-1">Copied to: {{ $copies->pluck('name')->implode(', ') }}</div>
                                @endif
                            </div>
                            @if ($paperStatus)
                                <div class="small text-right" data-test="paper-status">
                                    <i class="fas {{ $paperStatus[0] }} mr-1"></i>{{ $paperStatus[1] }}
                                </div>
                            @endif
                        </div>

                        @if ($document->replyTo || $document->replies->isNotEmpty())
                            <div class="small mt-3">
                                @if ($document->replyTo)<div><i class="fas fa-reply mr-1 text-muted"></i>Reply to <a href="{{ route('pdts.documents.show', $document->replyTo) }}">{{ $document->replyTo->reference_number }}</a></div>@endif
                                @foreach ($document->replies as $reply)
                                    <div><i class="fas fa-reply-all mr-1 text-muted"></i>Replied in <a href="{{ route('pdts.documents.show', $reply) }}">{{ $reply->reference_number }}</a></div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </section>
            </div>
        @endif

        {{-- Details (the whole width for a discarded draft, which has no copy) --}}
        <div class="{{ $isDiscarded ? 'col-12' : 'col-lg-8' }} mb-3">
            <section class="card elevation-2 border-0 h-100">
                <div class="card-body">
                    <h2 class="h6 font-weight-bold text-uppercase text-muted mb-3" style="letter-spacing: .04em;">Document details</h2>
                    @php
                        // The subject only when it is not the title shown at the top; where it came from on one line;
                        // who registered it (or prepared it, for a draft) and when on one line.
                        $subjectDiffers = trim((string) $document->subject) !== '' && mb_strtolower(trim((string) $document->subject)) !== mb_strtolower(trim((string) $document->title));
                        // The department alone, in full (a directorate's own office has none: the directorate then).
                        $department = $document->originating_department ?: $document->originating_directorate;
                        $byWhen = ($document->registrar?->name ?? 'Former user').($document->registered_at ? ', '.$document->registered_at->format('d M Y H:i') : '');
                    @endphp
                    <dl class="row mb-0" data-test="details">
                        @if ($subjectDiffers)
                            <dt class="col-sm-3 label pt-1">Subject</dt>
                            <dd class="col-sm-9 value mb-3">{{ $document->subject }}</dd>
                        @endif
                        {{-- A received document's own reference and date, when they were recorded. --}}
                        @if ($document->document_reference || $document->document_date)
                            <dt class="col-sm-3 label pt-1">Its reference</dt>
                            <dd class="col-sm-9 value mb-3">{{ collect([$document->document_reference, $document->document_date?->format('d M Y')])->filter()->implode(', ') }}</dd>
                        @endif
                        <dt class="col-sm-3 label pt-1">{{ $document->originating_department ? 'Department' : 'Directorate' }}</dt>
                        <dd class="col-sm-9 value mb-3">{{ $department ?: 'Not recorded' }}</dd>
                        <dt class="col-sm-3 label pt-1">{{ $onBehalf || $document->isDraft() ? 'Prepared by' : 'Registered by' }}</dt>
                        <dd class="col-sm-9 value mb-3">{{ $byWhen }}</dd>
                        @if ($onBehalf)
                            <dt class="col-sm-3 label pt-1">{{ $document->signature_status === 'signed' ? 'Signed by' : 'To be signed by' }}</dt>
                            <dd class="col-sm-9 value mb-3">{{ $document->signatory?->name ?? 'Former user' }}</dd>
                        @endif
                    </dl>

                    @if ($document->description)
                        <div class="label">Description</div>
                        <div class="note mt-1 mb-0" style="white-space: pre-line;">{{ $document->description }}</div>
                    @endif

                    @if ($canSeeVersions && $document->versions->count() > 1)
                        <div class="label mt-3">Versions</div>
                        <ul class="list-unstyled small mb-0" data-test="versions">
                            @foreach ($document->versions as $version)
                                <li class="py-1 {{ ! $loop->last ? 'border-bottom' : '' }}">
                                    <strong>Version {{ $version->version }}</strong> · {{ $version->reasonLabel() }} by {{ $version->author?->name ?? 'a former user' }}, {{ $version->created_at?->format('j M Y H:i') }}
                                    @if ($version->changed_fields)
                                        <span class="text-muted">· Changed: {{ implode(', ', $version->changed_fields) }}</span>
                                    @endif
                                    @if ($document->isDraft() && ! $document->template?->isPdf())
                                        <a href="{{ route('pdts.documents.versions.download', [$document, $version->version]) }}" class="ml-1">Download</a>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </section>
        </div>

        {{-- Scanned copy (none will come for a discarded draft) --}}
        @unless ($isDiscarded)
        <div class="col-lg-4 mb-3">
            <section class="card elevation-2 border-0 h-100">
                <div class="card-body">
                    <h2 class="h6 font-weight-bold text-uppercase text-muted mb-3" style="letter-spacing: .04em;">{{ $signature ? 'Signed copy' : 'Scanned copy' }}</h2>
                    @if ($signature)
                        @php
                            $unchanged = $signature->fileIsUnchanged();
                        @endphp
                        <div class="rounded p-3 mb-3" style="background: {{ $unchanged ? '#f3faf6' : '#fdf0f0' }};" data-test="signature-record">
                            <div class="font-weight-bold"><i class="fas fa-signature mr-1" style="color: #075B35;"></i>Signed electronically by {{ $signature->signer?->name ?? 'a former user' }}</div>
                            {{-- Who signed and when, and that the file is untouched. The rest (man number,
                                 who approved the signature image, the fingerprint) stays in the records. --}}
                            <div class="small text-muted">{{ $signature->signed_at->format('j M Y, H:i') }}</div>
                            <div class="small mt-2 {{ $unchanged ? 'text-success' : 'text-danger' }}">
                                <i class="fas {{ $unchanged ? 'fa-check-circle' : 'fa-exclamation-triangle' }} mr-1"></i>{{ $unchanged ? 'Unchanged since signing' : 'The stored PDF no longer matches the file that was signed.' }}
                            </div>
                        </div>
                    @endif
                    @forelse ($document->attachments as $attachment)
                        @php
                            [$icon, $colour] = $fileIcon($attachment);
                        @endphp
                        <div class="d-flex align-items-start {{ ! $loop->last ? 'border-bottom pb-3 mb-3' : '' }}" style="gap: .75rem;">
                            <span class="file-tile" style="color: {{ $colour }};"><i class="fas {{ $icon }}"></i></span>
                            <div style="min-width: 0;" class="flex-grow-1">
                                @php
                                    // The signed PDF is named plainly: its file name only repeats the reference and title.
                                    $isSignedPdf = $signature && (int) $signature->attachment_id === (int) $attachment->id;
                                @endphp
                                <div class="font-weight-bold text-break">{{ $isSignedPdf ? 'Signed PDF' : $attachment->original_filename }}</div>
                                <div class="small text-muted">
                                    {{ $fileSize((int) $attachment->file_size) }}
                                    @if ($attachment->uploader && ! $isSignedPdf) · {{ $attachment->uploader->name }} @endif
                                </div>
                                {{-- "Primary" only tells something when there is more than one file. --}}
                                @if ($attachment->is_primary && $document->attachments->count() > 1)
                                    <span class="badge badge-light border mt-1">Primary</span>
                                @endif
                                <div class="d-flex flex-wrap mt-2" style="gap: .375rem;">
                                    @if ($attachment->is_primary && $canViewHistory)
                                        <a href="{{ route('movements.scan', $document) }}" target="_blank" rel="noopener" class="btn btn-sm btn-primary text-white"><i class="fas fa-eye mr-1"></i>View</a>
                                    @endif
                                    <a href="{{ route('pdts.documents.attachments.download', [$document, $attachment]) }}" class="btn btn-sm btn-outline-secondary"><i class="fas fa-download mr-1"></i>Download</a>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-file-excel d-block mb-2" style="font-size: 1.75rem; opacity: .5;"></i>
                            No scanned file is attached.
                        </div>
                    @endforelse
                    @can('ai.ocr.use')
                        @if ($document->attachments->contains('is_primary', true))
                            <div class="border-top mt-4 pt-3">
                                <a href="{{ route('pdts.documents.analysis', $document) }}" class="btn btn-sm btn-outline-primary"><i class="fas fa-comment-alt mr-1" aria-hidden="true"></i>AI Analysis</a>
                            </div>
                        @endif
                    @endcan
                </div>
            </section>
        </div>
        @endunless
    </div>

    @if ($canCancel)
        <details class="mb-3" data-test="cancel-document" @error('cancelReason') open @enderror>
            <summary class="small text-muted" style="cursor: pointer;"><i class="fas fa-ban mr-1"></i>Cancel this document (administrators)</summary>
            <form wire:submit.prevent="cancelDocument" class="d-flex flex-wrap align-items-start mt-2" style="gap: .5rem;">
                <input type="text" class="form-control form-control-sm" style="max-width: 32rem;" maxlength="1000" wire:model="cancelReason" placeholder="Reason, e.g. issued in error, replaced by OMTS-…">
                <button type="submit" class="btn btn-sm btn-outline-danger" wire:confirm="Cancel {{ $document->reference_number }}? It can no longer be sent, decided on or completed. Its history and files are kept.">Cancel document</button>
            </form>
            @error('cancelReason') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
        </details>
    @endif

    {{-- Activity --}}
    <section class="card elevation-2 border-0">
        <div class="card-body">
            <h2 class="h6 font-weight-bold text-uppercase text-muted mb-3" style="letter-spacing: .04em;">Activity</h2>
            @if ($document->auditLogs->isEmpty())
                <p class="text-muted mb-0">No activity recorded yet.</p>
            @else
                @php
                    // Signing, registering and telling the people it is for happen in one step: one entry.
                    $logs = $document->auditLogs->sortByDesc(fn ($log) => [$log->occurred_at?->getTimestamp() ?? 0, $log->id])->values();
                    $registeredLog = $logs->firstWhere('action', 'registered');
                    $folded = $registeredLog ? $logs->filter(fn ($log) => in_array($log->action, ['signed_electronically', 'distributed'], true)
                        && (int) $log->actor_id === (int) $registeredLog->actor_id && $log->occurred_at && $registeredLog->occurred_at
                        && abs($log->occurred_at->diffInSeconds($registeredLog->occurred_at)) <= 120) : collect();
                    $foldedIds = $folded->pluck('id')->all();
                    $toldLog = $folded->firstWhere('action', 'distributed');
                    $toldNames = $toldLog ? \App\Models\User::query()->whereIn('id', array_filter([$toldLog->details['recipient_id'] ?? null, ...($toldLog->details['copied'] ?? [])]))->pluck('name')->implode(', ') : null;
                @endphp
                <ul class="timeline" data-activity>
                    @foreach ($logs as $event)
                        @continue(in_array($event->id, $foldedIds, true))
                        @php
                            [$title, $icon, $colour] = $activity[$event->action] ?? [str($event->action)->replace('_', ' ')->title(), 'fa-circle', '#6c757d'];
                            if ($registeredLog && $event->id === $registeredLog->id && $folded->contains('action', 'signed_electronically')) {
                                [$title, $icon, $colour] = ['Signed electronically and registered', 'fa-signature', '#075B35'];
                            }
                            $to = $people[$event->details['recipient_id'] ?? null] ?? null;
                            $note = $event->details['reason'] ?? $event->details['remarks'] ?? $event->details['comment'] ?? null;
                            if ($event->action === 'draft_edited' && ! empty($event->details['changed'])) {
                                $note = 'Version '.($event->details['version'] ?? '').' · Changed: '.implode(', ', $event->details['changed']);
                            }
                            if ($event->action === 'sent_for_signature' && $document->signatory) {
                                $note = 'To '.$document->signatory->name;
                            }
                            if ($registeredLog && $event->id === $registeredLog->id && $toldNames) {
                                $note = 'Told in PDTS: '.$toldNames;
                            }
                        @endphp
                        <li>
                            <span class="dot" style="background: {{ $colour }};"><i class="fas {{ $icon }}"></i></span>
                            <div class="flex-grow-1" style="min-width: 0;">
                                <div>
                                    <strong>{{ $title }}</strong>
                                    @if ($event->action === 'document_completed')
                                        ({{ \App\Services\Documents\CustodyService::COMPLETION_ACTIONS[$event->details['action_type'] ?? ''] ?? 'Other' }})
                                    @endif
                                    @if (in_array($event->action, ['handover_forwarded', 'handover_returned']) && $to) to {{ $to }} @endif
                                    <span class="text-muted">by {{ $event->actor?->name ?? 'Former user' }}</span>
                                    @if (($event->details['via'] ?? null) === 'qr_without_login')
                                        <span class="badge badge-warning ml-1" title="Confirmed from a scanned QR code by someone who was not logged in">confirmed by QR without login</span>
                                    @endif
                                </div>
                                @if ($note)
                                    <div class="note">{{ in_array($event->action, ['handover_declined', 'handover_cancelled', 'handover_returned']) ? 'Reason: ' : '' }}{{ $note }}</div>
                                @endif
                            </div>
                            <div class="when">
                                {{ $event->occurred_at?->format('d M Y H:i') }}
                                <div class="small">{{ $event->occurred_at?->diffForHumans() }}</div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </section>
</div>
