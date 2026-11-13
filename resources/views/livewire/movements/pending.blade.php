<div style="padding: 0 .75rem 90px;">
    <div class="mb-4">
        <h3 class="font-weight-bold mb-1">Pending Acknowledgements</h3>
        <p class="text-muted mb-0">Documents forwarded to you. Acknowledge each one only once you physically have the paper.</p>
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
                        <th>From / for</th>
                        <th>Date Sent</th>
                        <th>Remarks</th>
                        <th>Document</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($movements as $movement)
                        <tr wire:key="pending-{{ $movement->id }}">
                            <td class="text-nowrap">{{ $movement->document->reference_number }}</td>
                            <td>{{ $movement->document->title }}</td>
                            <td>{{ $movement->document->documentType?->name ?? '—' }}</td>
                            <td>
                                {{ $movement->sender->name }}
                                @if ($movement->messenger_id)
                                    <div class="small text-info">Messenger: {{ $movement->messenger?->name }}. {{ $movement->picked_up_at ? 'Picked up' : 'Awaiting pickup' }}</div>
                                @endif
                                @if((int) $movement->recipient_id !== (int) auth()->id())
                                    <div class="small text-info">For {{ $movement->recipient->name }}</div>
                                @endif
                            </td>
                            <td class="text-nowrap">{{ $movement->forwarded_at->format('d M Y H:i') }}</td>
                            <td>{{ $movement->remarks ?? '—' }}</td>
                            <td class="text-nowrap">
                                @if ($movement->document->attachments_count > 0)
                                    <a href="{{ route('movements.scan', $movement->document) }}" target="_blank" rel="noopener">
                                        <i class="fas fa-file-alt mr-1"></i>View
                                    </a>
                                @else
                                    <span class="text-muted">Not attached</span>
                                @endif
                            </td>
                            <td class="text-right text-nowrap">
                                <button type="button" class="btn btn-sm btn-success" wire:click="openAcknowledge({{ $movement->id }})" @disabled($movement->messenger_id && ! $movement->picked_up_at)>
                                    Acknowledge
                                </button>
                                @if((int) $movement->recipient_id === (int) auth()->id())
                                    <button type="button" class="btn btn-sm btn-outline-danger" wire:click="openDecline({{ $movement->id }})">
                                        Decline
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">Nothing is waiting for you.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $movements->links() }}</div>

    @if ($dialogMovement)
        <div class="modal d-block" tabindex="-1" role="dialog" aria-modal="true" style="background: rgba(0, 0, 0, .5);">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    @if ($dialogMode === 'acknowledge')
                        <div class="modal-header">
                            <h5 class="modal-title">Acknowledge receipt</h5>
                        </div>
                        <div class="modal-body">
                            <p class="mb-2"><strong>{{ $dialogMovement->document->reference_number }}</strong>: {{ $dialogMovement->document->title }}</p>
                            <p class="text-muted">From {{ $dialogMovement->sender->name }}</p>
                            @if((int) $dialogMovement->recipient_id !== (int) auth()->id())
                                <p class="font-weight-bold mb-1">I confirm I physically have this document as an authorized delegate for {{ $dialogMovement->recipient->name }}.</p>
                            @else
                                <p class="font-weight-bold mb-1">I confirm I physically have this document.</p>
                            @endif
                            <p class="small text-muted mb-0">You will become its recorded custodian.</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" wire:click="closeDialog">Not yet</button>
                            <button type="button" class="btn btn-success" wire:click="acknowledge" wire:loading.attr="disabled">
                                Yes, I have it
                            </button>
                        </div>
                    @else
                        <form wire:submit="decline">
                            <div class="modal-header">
                                <h5 class="modal-title">Decline document</h5>
                            </div>
                            <div class="modal-body">
                                <p class="mb-2"><strong>{{ $dialogMovement->document->reference_number }}</strong>: {{ $dialogMovement->document->title }}</p>
                                <p class="text-muted">{{ $dialogMovement->sender->name }} will remain the custodian.</p>
                                <label for="decline-reason">Reason</label>
                                <textarea id="decline-reason" rows="3" maxlength="1000" wire:model="reason"
                                          class="form-control @error('reason') is-invalid @enderror"></textarea>
                                @error('reason') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" wire:click="closeDialog">Back</button>
                                <button type="submit" class="btn btn-danger" wire:loading.attr="disabled">Decline</button>
                            </div>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
