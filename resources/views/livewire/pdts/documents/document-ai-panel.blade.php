<div class="doc-ai">
    <style>
        .doc-ai { min-width: 0; }
        .doc-ai .ai-text { white-space: pre-wrap; overflow-wrap: anywhere; }
        .doc-ai .ai-chat-log { max-height: 360px; overflow-y: auto; overscroll-behavior: contain; }
        .doc-ai fieldset { min-width: 0; }
        .doc-ai summary { cursor: pointer; }
        .doc-ai textarea { resize: vertical; min-height: 90px; max-height: 240px; }
        .doc-ai .ai-send { width: 36px; height: 34px; flex-shrink: 0; }
    </style>
    <h2 class="h6 font-weight-bold mb-2"><i class="fas fa-comment-alt mr-1" aria-hidden="true"></i>Analysis and chat</h2>
    <div class="small text-muted mb-3">Draft analysis &middot; Primary scan</div>

    <fieldset class="border-0 p-0 m-0" wire:loading.attr="disabled" wire:target="analyse,ask,clearChat">
        @if ($summary === [])
            <button type="button" wire:click="analyse" class="btn btn-sm btn-outline-primary">
                <i class="fas fa-search mr-1" aria-hidden="true"></i>{{ $analysisError ? 'Retry analysis' : 'Analyse with AI' }}
            </button>
        @endif

        <div wire:loading wire:target="analyse" class="small text-muted mt-2" role="status">
            <i class="fas fa-spinner fa-spin mr-1" aria-hidden="true"></i>Analysing the scan...
        </div>
        @if ($analysisError)
            <div class="alert alert-warning small mt-3 mb-2" role="alert">{{ $analysisError }}</div>
        @endif

        @if ($summary !== [])
            <div class="small text-muted text-break mb-2">{{ $scanName }}</div>
            <h4 class="h6 font-weight-bold">Summary</h4>
            <p class="ai-text small mb-1">{{ $summary['summary']['text'] }}</p>
            @if ($summary['summary']['pages'])
                <div class="small text-muted mb-2">Source pages: {{ implode(', ', $summary['summary']['pages']) }}</div>
            @endif
            @foreach (['key_points' => 'Key points', 'requested_actions' => 'Requested actions', 'dates_and_amounts' => 'Dates and amounts'] as $key => $label)
                <details class="border-top mt-3 pt-2" wire:key="ai-section-{{ $key }}" wire:ignore.self>
                    <summary class="small font-weight-bold">{{ $label }}</summary>
                    <ul class="small pl-3 mt-2 mb-0">
                        @forelse ($summary[$key] as $item)
                            <li class="mb-2">
                                <span class="ai-text">{{ $item['text'] }}</span>
                                @if ($item['pages'])
                                    <div class="text-muted">Source pages: {{ implode(', ', $item['pages']) }}</div>
                                @endif
                            </li>
                        @empty
                            <li class="text-muted">Not stated in the document.</li>
                        @endforelse
                    </ul>
                </details>
            @endforeach

            <details class="border-top mt-3 pt-3" wire:key="document-ai-chat" wire:ignore.self open>
                <summary class="font-weight-bold small"><i class="fas fa-comments mr-1" aria-hidden="true"></i>Ask about this document</summary>
                <div class="ai-chat-log mt-3" role="log" aria-live="polite" aria-label="Document conversation">
                    @foreach ($messages as $message)
                        <div class="border-bottom pb-2 mb-2" wire:key="ai-message-{{ $loop->index }}">
                            <div class="small font-weight-bold {{ $message['role'] === 'user' ? 'text-primary' : '' }}">{{ $message['role'] === 'user' ? 'You' : 'Document AI' }}</div>
                            <div class="ai-text small">{{ $message['text'] }}</div>
                            @if ($message['pages'])
                                <div class="small text-muted">Source pages: {{ implode(', ', $message['pages']) }}</div>
                            @endif
                            @if (! empty($message['tracking_as_of']))
                                <div class="small text-muted">Tracking snapshot: {{ \Carbon\Carbon::parse($message['tracking_as_of'])->format('d M Y H:i:s P') }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
                <form wire:submit.prevent="ask" class="mt-3">
                    <label for="document-ai-question-{{ $documentId }}" class="small">Question</label>
                    <textarea id="document-ai-question-{{ $documentId }}" wire:model="question" class="form-control form-control-sm" rows="3" maxlength="1000" required @disabled(count($messages) >= 10)></textarea>
                    @error('question') <div class="text-danger small mt-1" role="alert">{{ $message }}</div> @enderror
                    <div class="d-flex align-items-center justify-content-between mt-2" style="gap: .5rem;">
                        <span class="small text-muted">{{ intdiv(count($messages), 2) }}/5 questions</span>
                        <div class="d-flex" style="gap: .375rem;">
                            @if ($messages)
                                <button type="button" wire:click="clearChat" class="btn btn-sm btn-outline-secondary" title="Clear chat" aria-label="Clear chat"><i class="fas fa-trash-alt" aria-hidden="true"></i></button>
                            @endif
                            <button type="submit" class="btn btn-sm btn-primary ai-send" title="Send question" aria-label="Send question" @disabled(count($messages) >= 10)><i class="fas fa-paper-plane" aria-hidden="true"></i></button>
                        </div>
                    </div>
                </form>
                <div wire:loading wire:target="ask" class="small text-muted mt-2" role="status"><i class="fas fa-spinner fa-spin mr-1" aria-hidden="true"></i>Reading the document...</div>
            </details>
        @endif
    </fieldset>
</div>