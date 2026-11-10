<div style="padding: 0 .75rem 90px;">
    <div class="d-flex justify-content-between align-items-start flex-wrap mb-3">
        <div class="mb-2 mr-3">
            <h3 class="font-weight-bold mb-1">Documents in My Custody</h3>
            <p class="text-muted mb-0">You stay responsible for a document until its recipient acknowledges receipt.</p>
        </div>

        @include('livewire.movements.partials.search')
    </div>

    @include('livewire.movements.partials.alerts')

    <div class="card elevation-2 border-0">
        <div class="card-body p-0 table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Subject</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($documents as $document)
                        <tr wire:key="custody-{{ $document->id }}">
                            <td class="text-nowrap">{{ $document->reference_number }}</td>
                            <td>{{ $document->title }}</td>
                            <td>{{ $document->documentType?->name ?? '—' }}</td>
                            @php($last = $document->latestMovement)
                            <td>
                                {{ $document->statusEnum()->plainLabel() }}
                                <div class="small text-muted" data-status-hint>
                                    @php($reason = $last?->close_reason ? rtrim($last->close_reason, '. ').'.' : null)
                                    @if ($document->pendingMovement)
                                        Forwarded to {{ $document->pendingMovement->recipient->name }}. Waiting for them to acknowledge receipt.
                                    @elseif ($last?->status === \App\Enums\MovementStatus::Declined)
                                        {{ $last->closedBy?->name }} declined it and returned it to you. Reason: {{ $reason }}
                                    @elseif ($last?->status === \App\Enums\MovementStatus::Cancelled)
                                        The handover to {{ $last->recipient?->name }} was cancelled. Reason: {{ $reason }}
                                    @elseif ($last?->status === \App\Enums\MovementStatus::Acknowledged)
                                        Received from {{ $last->sender?->name }} on {{ $last->acknowledged_at->format('d M Y') }} at {{ $last->acknowledged_at->format('H:i') }}.
                                    @else
                                        Not yet forwarded.
                                    @endif
                                </div>
                            </td>
                            <td class="text-right">
                                @if ($document->pendingMovement)
                                    <button type="button" class="btn btn-sm btn-outline-danger" wire:click="openCancel({{ $document->pendingMovement->id }})">
                                        Cancel
                                    </button>
                                @else
                                    <livewire:movements.forward-document :document="$document" :key="'forward-'.$document->id" />
                                    @if ($document->statusEnum() === \App\Enums\DocumentStatus::Received)
                                        @can('documents.complete')
                                            <button type="button" class="btn btn-sm btn-outline-success ml-1" wire:click="openComplete({{ $document->id }})">
                                                Complete
                                            </button>
                                        @endcan
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                {{ $search === '' ? 'You are not holding any documents.' : 'No documents match "'.$search.'".' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $documents->links() }}</div>

    @if ($cancellingMovement)
        <div class="modal d-block" tabindex="-1" role="dialog" aria-modal="true" style="background: rgba(0, 0, 0, .5);">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <form wire:submit="cancel">
                        <div class="modal-header">
                            <h5 class="modal-title">Cancel handover</h5>
                        </div>
                        <div class="modal-body">
                            <p class="mb-2"><strong>{{ $cancellingMovement->document->reference_number }}</strong>: {{ $cancellingMovement->document->title }}</p>
                            <p class="text-muted">To {{ $cancellingMovement->recipient->name }}. You keep the document and can forward it again.</p>
                            <label for="cancel-reason">Reason</label>
                            <textarea id="cancel-reason" rows="3" maxlength="1000" wire:model="reason"
                                      class="form-control @error('reason') is-invalid @enderror"></textarea>
                            @error('reason') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" wire:click="closeCancel">Back</button>
                            <button type="submit" class="btn btn-danger" wire:loading.attr="disabled">Cancel handover</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    @if ($completingDocument)
        <div class="modal d-block" tabindex="-1" role="dialog" aria-modal="true" style="background: rgba(0, 0, 0, .5);">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <form wire:submit.prevent="complete">
                        <div class="modal-header">
                            <h5 class="modal-title">Complete document</h5>
                        </div>
                        <div class="modal-body">
                            <p class="mb-2"><strong>{{ $completingDocument->reference_number }}</strong>: {{ $completingDocument->title }}</p>
                            <p class="text-muted">This ends ordinary forwarding. The movement history will remain available.</p>
                            <label for="completion-comments">Final action and comments</label>
                            <textarea id="completion-comments" rows="3" maxlength="1000" wire:model="completionComments"
                                      class="form-control @error('completionComments') is-invalid @enderror" required></textarea>
                            @error('completionComments') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" wire:click="closeComplete">Back</button>
                            <button type="submit" class="btn btn-success" wire:loading.attr="disabled">Complete</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>
