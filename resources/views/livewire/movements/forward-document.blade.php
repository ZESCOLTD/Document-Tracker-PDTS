<div class="text-left">
    @if ($notice)
        <div class="small text-success mb-1" role="status">{{ $notice }}</div>
    @endif

    @if ($canForward)
        <div class="text-right">
            <button type="button" class="btn btn-sm btn-primary text-white" wire:click="openForm">
                <i class="fas fa-share mr-1"></i>Forward
            </button>
        </div>
    @elseif (! $notice && $document?->status === \App\Enums\DocumentStatus::AwaitingReceipt)
        <div class="text-right small text-muted">Waiting for receipt</div>
    @endif

    @if ($open && $document)
        <div class="modal d-block" tabindex="-1" role="dialog" aria-modal="true" style="background: rgba(0, 0, 0, .5);">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <form wire:submit="forward">
                        <div class="modal-header">
                            <h5 class="modal-title">Forward document</h5>
                        </div>
                        <div class="modal-body" style="white-space: normal;">
                            <p class="mb-2"><strong>{{ $document->reference_number }}</strong>: {{ $document->title }}</p>
                            <p class="small text-muted">Hand over the paper, then record it here. You stay the custodian until the recipient acknowledges.</p>

                            @if ($errorMessage)
                                <div class="alert alert-danger" role="alert">{{ $errorMessage }}</div>
                            @endif

                            <div class="form-group">
                                <label for="forward-recipient-{{ $documentId }}">Recipient</label>

                                @if ($selectedRecipient)
                                    <div class="d-flex justify-content-between align-items-center border rounded p-2">
                                        <span>
                                            {{ $selectedRecipient->name }}
                                            <span class="small text-muted d-block">{{ $selectedRecipient->man_no }} {{ $selectedRecipient->job_title }}</span>
                                        </span>
                                        <button type="button" class="btn btn-sm btn-link" wire:click="clearRecipient">Change</button>
                                    </div>
                                @else
                                    <input id="forward-recipient-{{ $documentId }}" type="search" autocomplete="off"
                                           placeholder="Search by name, man number or job title"
                                           wire:model.live.debounce.300ms="search"
                                           class="form-control @error('recipientId') is-invalid @enderror">
                                    @error('recipientId') <div class="invalid-feedback">{{ $message }}</div> @enderror

                                    @if ($matches->isNotEmpty())
                                        <div class="list-group mt-1">
                                            @foreach ($matches as $match)
                                                <button type="button" wire:key="match-{{ $match->id }}"
                                                        class="list-group-item list-group-item-action py-2"
                                                        wire:click="selectRecipient({{ $match->id }})">
                                                    {{ $match->name }}
                                                    <span class="small text-muted d-block">{{ $match->man_no }} {{ $match->job_title }}</span>
                                                </button>
                                            @endforeach
                                        </div>
                                    @elseif (mb_strlen(trim($search)) >= 2)
                                        <div class="small text-muted mt-1">No eligible users found.</div>
                                    @endif
                                @endif
                            </div>

                            <div class="form-group mb-0">
                                <label for="forward-remarks-{{ $documentId }}">Remarks (optional)</label>
                                <textarea id="forward-remarks-{{ $documentId }}" rows="2" maxlength="1000" wire:model="remarks"
                                          class="form-control @error('remarks') is-invalid @enderror"></textarea>
                                @error('remarks') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" wire:click="closeForm">Close</button>
                            <button type="submit" class="btn btn-primary text-white" wire:loading.attr="disabled">Forward</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>
