<div>
    <style>
        .scan-card { background: #fff; border-radius: .75rem; box-shadow: 0 1px 6px rgba(0, 0, 0, .08); padding: 1.125rem; margin-bottom: 1rem; }
        .scan-status { display: inline-flex; align-items: center; gap: .375rem; padding: .25rem .75rem; border-radius: 999px; font-size: .875rem; font-weight: 600; }
        .scan-where { display: flex; gap: .75rem; align-items: flex-start; }
        .scan-where .icon { width: 2.5rem; height: 2.5rem; border-radius: 50%; display: grid; place-items: center; flex-shrink: 0; }
        .scan-links a { display: flex; justify-content: space-between; padding: .75rem 0; border-top: 1px solid #eef0f3; color: #1f2d3d; }
        .scan-links a:first-child { border-top: 0; }
        .scan-journey { list-style: none; margin: 0; padding: 0; }
        .scan-journey li { position: relative; display: flex; gap: .75rem; padding-bottom: 1rem; }
        .scan-journey li:last-child { padding-bottom: 0; }
        .scan-journey li:not(:last-child)::before { content: ""; position: absolute; left: .4375rem; top: 1.125rem; bottom: 0; width: 2px; background: #e9ecef; }
        .scan-journey .dot { width: 1rem; height: 1rem; border-radius: 50%; background: #075B35; flex-shrink: 0; margin-top: .25rem; }
        .scan-journey .type-awaiting_receipt .dot, .scan-journey .type-returned .dot { background: #17a2b8; }
        .scan-journey .type-declined .dot, .scan-journey .type-cancelled .dot { background: #dc3545; }
        .scan-journey .type-picked_up .dot { background: #f59d33; }
    </style>

    @if ($notice)
        <div class="alert alert-success" role="status"><i class="fas fa-check-circle mr-1"></i>{{ $notice }}</div>
    @endif
    @if ($errorMessage)
        <div class="alert alert-danger" role="alert"><i class="fas fa-exclamation-circle mr-1"></i>{{ $errorMessage }}</div>
    @endif

    @if (! $involved)
        {{-- Someone not involved: no names, title or content. --}}
        <section class="scan-card text-center">
            <div class="mb-3" style="font-size: 2.5rem; color: #b86e00;"><i class="fas fa-hand-paper"></i></div>
            <h1 class="h5 font-weight-bold">This document is tracked in PDTS and is not addressed to you.</h1>
            <p class="text-muted mb-0">Please don't keep it. Tap below and the people responsible will be told, or hand it to your nearest registry.</p>
        </section>

        @if ($alreadyReported && ! $notice)
            <div class="alert alert-info"><i class="fas fa-info-circle mr-1"></i>You have already reported this document. The people responsible have been told.</div>
        @elseif (! $alreadyReported)
            @if ($form === 'report')
                <form wire:submit="reportFound" class="scan-card">
                    <label for="scan-note" class="font-weight-bold">Where is it now? <span class="text-muted font-weight-normal">(optional)</span></label>
                    <textarea id="scan-note" class="form-control mb-2" rows="2" wire:model="note" maxlength="500" placeholder="e.g. Left at Procurement reception"></textarea>
                    @error('note') <div class="text-danger small mb-2">{{ $message }}</div> @enderror
                    <button type="submit" class="btn btn-primary btn-lg btn-block" wire:loading.attr="disabled">Send report</button>
                    <button type="button" class="btn btn-link btn-block text-muted" wire:click="closeForm">Back</button>
                </form>
            @else
                <button type="button" class="btn btn-primary btn-lg btn-block" wire:click="openForm('report')">
                    <i class="fas fa-flag mr-1"></i>Report found / wrong delivery
                </button>
            @endif
        @endif
    @else
        @php
            $tone = match ($status) {
                \App\Enums\DocumentStatus::Completed => ['#e8f5ec', '#218838', 'fa-check-double'],
                \App\Enums\DocumentStatus::Received => ['#e7f1ff', '#1f5fbf', 'fa-check-circle'],
                \App\Enums\DocumentStatus::AwaitingReceipt => ['#fff4e0', '#b86e00', 'fa-shipping-fast'],
                \App\Enums\DocumentStatus::Draft => ['#f1f3f5', '#495057', 'fa-pencil-alt'],
                default => ['#eef0f3', '#4a5563', 'fa-file-alt'],
            };
            $hasAction = in_array(true, $can, true);
        @endphp

        {{-- Where the document is --}}
        <section class="scan-card">
            <div class="small text-muted">{{ $document->documentType?->name ?? 'Document' }}</div>
            <h1 class="h4 font-weight-bold mb-1">{{ $document->reference_number }}</h1>
            <p class="mb-2" style="font-size: 1.0625rem;">{{ $document->title }}</p>
            <span class="scan-status" style="background: {{ $tone[0] }}; color: {{ $tone[1] }};"><i class="fas {{ $tone[2] }}"></i>{{ $status->plainLabel() }}</span>

            <hr>

            @if ($pending)
                <div class="scan-where">
                    <span class="icon" style="background: #ffe7c2; color: #b86e00;"><i class="fas fa-shipping-fast"></i></span>
                    <div>
                        <div class="font-weight-bold">On its way to {{ (int) $pending->recipient_id === (int) auth()->id() ? 'you' : ($pending->recipient?->name ?? 'a former user') }}</div>
                        <div class="small text-muted">
                            Sent by {{ $pending->sender?->name ?? 'a former user' }} {{ $pending->forwarded_at?->diffForHumans() }}.
                            @if ($pending->messenger_id)
                                Messenger: {{ $pending->messenger?->name }}, {{ $pending->picked_up_at ? 'picked up '.$pending->picked_up_at->diffForHumans() : 'not picked up yet' }}.
                            @endif
                        </div>
                        @if ($pending->remarks)
                            <div class="small mt-1" style="background: #f7f8fa; border-radius: .25rem; padding: .25rem .5rem;">"{{ $pending->remarks }}"</div>
                        @endif
                    </div>
                </div>
            @elseif ($status === \App\Enums\DocumentStatus::Completed)
                <div class="scan-where">
                    <span class="icon" style="background: #d7f0de; color: #218838;"><i class="fas fa-check-double"></i></span>
                    <div>
                        <div class="font-weight-bold">Completed. Final holder: {{ $document->currentCustodian?->name ?? 'a former user' }}</div>
                        <div class="small text-muted">It is no longer moving.</div>
                    </div>
                </div>
            @else
                <div class="scan-where">
                    <span class="icon" style="background: #dce9ff; color: #1f5fbf;"><i class="fas fa-user-check"></i></span>
                    <div>
                        <div class="font-weight-bold">With {{ $isHolder ? 'you' : ($document->currentCustodian?->name ?? 'a former user') }}</div>
                        @if ($heldSince)
                            <div class="small text-muted">Since {{ $heldSince->format('d M Y H:i') }} ({{ $heldSince->diffForHumans(null, true) }})</div>
                        @endif
                    </div>
                </div>
            @endif
        </section>

        {{-- The action that fits this person --}}
        @if ($status === \App\Enums\DocumentStatus::Draft && $isHolder)
            <div class="alert alert-secondary">This is a draft. Print and sign it, then upload the signed scan from the full page.</div>
        @endif

        @if ($form === 'decline' || $form === 'cancel')
            <form wire:submit="{{ $form === 'decline' ? 'decline' : 'cancelHandover' }}" class="scan-card">
                <label for="scan-reason" class="font-weight-bold">{{ $form === 'decline' ? 'Why are you declining it?' : 'Why are you cancelling the handover?' }}</label>
                <textarea id="scan-reason" class="form-control mb-2" rows="3" wire:model="reason" maxlength="1000" required></textarea>
                @error('reason') <div class="text-danger small mb-2">{{ $message }}</div> @enderror
                <button type="submit" class="btn btn-danger btn-lg btn-block" wire:loading.attr="disabled">{{ $form === 'decline' ? 'Decline' : 'Cancel handover' }}</button>
                <button type="button" class="btn btn-link btn-block text-muted" wire:click="closeForm">Back</button>
            </form>
        @elseif ($form === 'sendBack')
            <form wire:submit="sendBack" class="scan-card">
                <div class="font-weight-bold mb-2">Send back for correction</div>
                <select class="form-control mb-2" wire:model="returnCategory" aria-label="Why it is being sent back" required>
                    <option value="">Why is it being sent back?</option>
                    @foreach ($returnCategories as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('returnCategory') <div class="text-danger small mb-2">{{ $message }}</div> @enderror
                <textarea class="form-control mb-2" rows="3" wire:model="reason" maxlength="1000" placeholder="What needs correcting?" aria-label="What needs correcting" required></textarea>
                @error('reason') <div class="text-danger small mb-2">{{ $message }}</div> @enderror
                <button type="submit" class="btn btn-primary btn-lg btn-block" wire:loading.attr="disabled"><i class="fas fa-undo mr-1"></i>Send back</button>
                <button type="button" class="btn btn-link btn-block text-muted" wire:click="closeForm">Back</button>
            </form>
        @elseif ($form === 'complete')
            <form wire:submit="complete" class="scan-card">
                <div class="font-weight-bold mb-2">Complete the document</div>
                <select class="form-control mb-2" wire:model="completionAction" aria-label="What was done" required>
                    <option value="">What was done?</option>
                    @foreach ($completionActions as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('completionAction') <div class="text-danger small mb-2">{{ $message }}</div> @enderror
                <textarea class="form-control mb-2" rows="3" wire:model="reason" maxlength="1000" placeholder="Short remark" aria-label="Short remark" required></textarea>
                @error('reason') <div class="text-danger small mb-2">{{ $message }}</div> @enderror
                <button type="submit" class="btn btn-success btn-lg btn-block" wire:loading.attr="disabled"><i class="fas fa-check-double mr-1"></i>Complete</button>
                <button type="button" class="btn btn-link btn-block text-muted" wire:click="closeForm">Back</button>
            </form>
        @elseif ($hasAction)
            <section class="mb-3">
                @if ($can['pickup'])
                    <button type="button" class="btn btn-primary btn-lg btn-block" wire:click="confirmPickup" wire:loading.attr="disabled">
                        <i class="fas fa-truck mr-1"></i>Confirm pickup
                    </button>
                @endif

                @if ($can['acknowledge'])
                    @if ($waitingForPickup)
                        <div class="alert alert-warning mb-2">The messenger must confirm pickup before you can confirm receipt.</div>
                    @else
                        <button type="button" class="btn btn-success btn-lg btn-block" wire:click="acknowledge" wire:loading.attr="disabled">
                            <i class="fas fa-check mr-1"></i>Confirm receipt{{ (int) $pending->recipient_id !== (int) auth()->id() ? ' for '.$pending->recipient?->name : '' }}
                        </button>
                    @endif
                @endif
                @if ($can['decline'])
                    <button type="button" class="btn btn-outline-danger btn-block mt-2" wire:click="openForm('decline')">Decline</button>
                @endif

                @if ($can['cancel'])
                    <button type="button" class="btn btn-outline-danger btn-block mt-2" wire:click="openForm('cancel')">Cancel handover</button>
                @endif

                @if ($can['forward'])
                    <div class="scan-card mb-2 d-flex align-items-center justify-content-between" style="gap: .5rem;">
                        <span class="font-weight-bold">Send it to the next person</span>
                        <livewire:movements.forward-document :document="$document" :key="'scan-forward-'.$document->id" />
                    </div>
                @endif
                @if ($can['sendBack'])
                    <button type="button" class="btn btn-outline-secondary btn-block mt-2" wire:click="openForm('sendBack')"><i class="fas fa-undo mr-1"></i>Send back for correction</button>
                @endif
                @if ($can['complete'])
                    <button type="button" class="btn btn-outline-success btn-block mt-2" wire:click="openForm('complete')"><i class="fas fa-check-double mr-1"></i>Complete</button>
                @endif
            </section>
        @endif

        @if ($events)
            <section class="scan-card">
                <h2 class="h6 font-weight-bold text-uppercase text-muted mb-3" style="letter-spacing: .04em;">Journey</h2>
                <ol class="scan-journey">
                    @foreach ($events as $event)
                        <li class="type-{{ $event['type'] }}" wire:key="journey-{{ $event['order'] }}">
                            <span class="dot"></span>
                            <div>
                                <div class="font-weight-bold">{{ $event['title'] }}: {{ $event['office'] }}</div>
                                <div class="small text-muted">
                                    {{ $event['person'] }}@if ($event['person'] && $event['at']) · @endif{{ $event['at']?->format('d M Y H:i') }}
                                </div>
                                @foreach ($event['details'] as $detail)
                                    <div class="small text-muted">{{ $detail }}</div>
                                @endforeach
                                @if ($event['remarks'])
                                    <div class="small mt-1">"{{ $event['remarks'] }}"</div>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            </section>
        @endif

        <form wire:submit="sendHistorySms" class="scan-card">
            <label for="scan-sms" class="font-weight-bold mb-1"><i class="fas fa-sms mr-1 text-muted"></i>Send this history to my phone</label>
            <div class="d-flex" style="gap: .5rem;">
                <input id="scan-sms" type="tel" inputmode="tel" autocomplete="tel" class="form-control @error('smsPhone') is-invalid @enderror"
                       wire:model="smsPhone" placeholder="0977 123 456" maxlength="20" required>
                <button type="submit" class="btn btn-success" style="white-space: nowrap;" wire:loading.attr="disabled" wire:target="sendHistorySms">Send SMS</button>
            </div>
            @error('smsPhone') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
            <div class="small text-muted mt-1">You'll get the reference, where it is now and its recent journey.</div>
        </form>

        <section class="scan-card scan-links py-1" @if ($guest) style="display: none;" @endif>
            @if ($document->primaryAttachment)
                <a href="{{ route('movements.scan', $document) }}" target="_blank" rel="noopener">View scanned copy <i class="fas fa-chevron-right text-muted"></i></a>
            @endif
            @if ($fullPageUrl)
                <a href="{{ $fullPageUrl }}">Open in the full app <span class="small text-muted">(for computers) <i class="fas fa-chevron-right"></i></span></a>
            @endif
        </section>
    @endif
</div>
