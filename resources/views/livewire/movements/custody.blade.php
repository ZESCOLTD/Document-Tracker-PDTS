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
                                    @if ($document->pendingMovement?->isReturn())
                                        Sent back to {{ $document->pendingMovement->recipient->name }} for correction. Waiting for them to acknowledge.
                                    @elseif ($document->pendingMovement)
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
                                @if ($document->pendingMovement?->messenger_id)
                                    <div class="small text-info">
                                        Messenger: {{ $document->pendingMovement->messenger?->name }}.
                                        {{ $document->pendingMovement->picked_up_at ? 'Picked up' : 'Awaiting pickup' }}
                                    </div>
                                @endif
                            </td>
                            <td class="text-right">
                                @if ($document->pendingMovement)
                                    <button type="button" class="btn btn-sm btn-outline-danger" wire:click="openCancel({{ $document->pendingMovement->id }})">
                                        Cancel
                                    </button>
                                @else
                                    <div x-data="{
                                        expanded: false,
                                        menuTop: 0,
                                        menuRight: 8,
                                        toggle() {
                                            this.expanded = !this.expanded;
                                            if (!this.expanded) return;
                                            this.$nextTick(() => {
                                                const trigger = this.$refs.trigger.getBoundingClientRect();
                                                const height = this.$refs.menu.offsetHeight;
                                                this.menuRight = Math.max(8, window.innerWidth - trigger.right);
                                                this.menuTop = trigger.bottom + height + 4 > window.innerHeight
                                                    ? Math.max(8, trigger.top - height - 4)
                                                    : trigger.bottom + 4;
                                                this.$refs.menu.querySelector('button')?.focus();
                                            });
                                        },
                                        moveFocus(direction) {
                                            const items = Array.from(this.$refs.menu.querySelectorAll('button'));
                                            const index = items.indexOf(document.activeElement);
                                            items[(index + direction + items.length) % items.length].focus();
                                        }
                                    }"
                                         x-on:keydown.escape.stop.prevent="expanded = false; $refs.trigger.focus()"
                                         x-on:resize.window="expanded = false"
                                         x-on:scroll.window="expanded = false">
                                        <button type="button" class="btn btn-sm btn-primary dropdown-toggle" x-ref="trigger"
                                                x-on:click="toggle()" x-on:keydown.arrow-down.prevent="if (!expanded) toggle()"
                                                aria-haspopup="menu" x-bind:aria-expanded="expanded"
                                                aria-controls="custody-actions-{{ $document->id }}">
                                            <i class="fas fa-ellipsis-v mr-1" aria-hidden="true"></i>Actions
                                        </button>
                                        <template x-teleport="body">
                                            <div class="dropdown-menu show text-left" id="custody-actions-{{ $document->id }}"
                                                 role="menu" aria-label="Document actions" x-ref="menu" x-show="expanded"
                                                 style="display: none; position: fixed; z-index: 1060; min-width: 11rem;"
                                                 x-bind:style="{ top: menuTop + 'px', right: menuRight + 'px', left: 'auto' }"
                                                 x-on:click.outside="expanded = false"
                                                 x-on:keydown.escape.stop.prevent="expanded = false; $refs.trigger.focus()"
                                                 x-on:keydown.arrow-down.prevent="moveFocus(1)"
                                                 x-on:keydown.arrow-up.prevent="moveFocus(-1)"
                                                 x-on:keydown.tab="expanded = false">
                                                @if (app(\App\Services\Documents\CustodyService::class)->forwardBlockedReason($document, auth()->user()) === null)
                                                    <button type="button" class="dropdown-item" role="menuitem"
                                                            x-on:click="expanded = false; $refs.trigger.focus(); $dispatch('open-forward.{{ $document->id }}')">
                                                        <i class="fas fa-share fa-fw mr-2" aria-hidden="true"></i>Forward
                                                    </button>
                                                @endif
                                                @if ($document->statusEnum() === \App\Enums\DocumentStatus::Received)
                                                    <button type="button" class="dropdown-item" role="menuitem"
                                                            x-on:click="expanded = false; $refs.trigger.focus(); $wire.openComplete({{ $document->id }})">
                                                        <i class="fas fa-check-double fa-fw mr-2 text-success" aria-hidden="true"></i>Complete
                                                    </button>
                                                @endif
                                                @if (! empty($returnTargets[$document->id]))
                                                    <button type="button" class="dropdown-item" role="menuitem"
                                                            x-on:click="expanded = false; $refs.trigger.focus(); $wire.openSendBack({{ $document->id }})">
                                                        <i class="fas fa-undo fa-fw mr-2 text-warning" aria-hidden="true"></i>Send back
                                                    </button>
                                                @endif
                                            </div>
                                        </template>
                                    </div>
                                    <livewire:movements.forward-document :document="$document" :show-trigger="false" :key="'forward-'.$document->id" />
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
        <div class="modal d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="complete-title" style="background: rgba(0, 0, 0, .5);">
            <div class="modal-dialog" role="document">
                <form wire:submit="complete" class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="complete-title">Complete {{ $completingDocument->reference_number }}</h5>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <label for="completion-action">Action completed</label>
                            <select id="completion-action" wire:model="completionAction" class="form-control @error('completionAction') is-invalid @enderror">
                                <option value="">Choose...</option>
                                @foreach (\App\Services\Documents\CustodyService::COMPLETION_ACTIONS as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('completionAction') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <label for="completion-reason">Completion details</label>
                        <textarea id="completion-reason" wire:model="completionReason" rows="3" maxlength="1000" class="form-control @error('completionReason') is-invalid @enderror"></textarea>
                        @error('completionReason') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="closeComplete">Cancel</button>
                        <button type="submit" class="btn btn-success" wire:loading.attr="disabled" wire:confirm="Completion is final. This document cannot be reopened or forwarded. Continue?">
                            <i class="fas fa-check-double mr-1"></i>Complete permanently
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if ($returningDocument)
        <div class="modal d-block" tabindex="-1" role="dialog" aria-modal="true" style="background: rgba(0, 0, 0, .5);">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <form wire:submit="sendBack">
                        <div class="modal-header">
                            <h5 class="modal-title">Send document back</h5>
                        </div>
                        <div class="modal-body">
                            <p class="mb-2"><strong>{{ $returningDocument->reference_number }}</strong>: {{ $returningDocument->title }}</p>
                            <p class="text-muted">Returns to {{ $returningTarget?->name ?? 'the previous holder' }} for correction. You stay the custodian until they acknowledge.</p>

                            <div class="form-group">
                                <label for="return-category">Reason for sending back</label>
                                <select id="return-category" wire:model="returnCategory"
                                        class="form-control @error('returnCategory') is-invalid @enderror">
                                    <option value="">Choose...</option>
                                    @foreach (\App\Models\DocumentMovement::RETURN_CATEGORIES as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('returnCategory') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <div class="form-group">
                                <label for="return-messenger">Messenger (optional)</label>
                                <select id="return-messenger" wire:model="returnMessengerId" class="form-control @error('returnMessengerId') is-invalid @enderror">
                                    <option value="">Direct handover</option>
                                    @foreach ($returnMessengers as $messenger)
                                        <option value="{{ $messenger->id }}">{{ $messenger->name }} ({{ $messenger->man_no }})</option>
                                    @endforeach
                                </select>
                                @error('returnMessengerId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <div class="form-group mb-0">
                                <label for="return-reason">Details</label>
                                <textarea id="return-reason" rows="3" maxlength="1000" wire:model="returnReason"
                                          class="form-control @error('returnReason') is-invalid @enderror"></textarea>
                                @error('returnReason') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" wire:click="closeSendBack">Back</button>
                            <button type="submit" class="btn btn-warning" wire:loading.attr="disabled">Send back</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>
