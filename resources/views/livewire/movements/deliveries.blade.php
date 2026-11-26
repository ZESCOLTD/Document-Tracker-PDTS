<div style="padding: 0 .75rem 90px;">
    <h3 class="font-weight-bold mb-4">My Deliveries</h3>

    @if ($pickupNotice)
        <div class="alert alert-success" role="status">{{ $pickupNotice }}</div>
    @endif
    @if ($pickupError)
        <div class="alert alert-danger" role="alert">{{ $pickupError }}</div>
    @endif

    @include('livewire.movements.partials.filter-cards')

    <div class="card elevation-2 border-0">
        <div class="card-body p-0 table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Subject</th>
                        <th>Collect from</th>
                        <th>Deliver to</th>
                        <th class="text-right">{{ $statusFilter === 'completed' ? 'Delivery' : 'Pickup' }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($deliveries as $delivery)
                        <tr wire:key="delivery-{{ $delivery->id }}">
                            <td class="text-nowrap">
                                <a href="{{ route('movements.history', $delivery->document) }}">{{ $delivery->document->reference_number }}</a>
                            </td>
                            <td class="text-break">{{ $delivery->document->title }}</td>
                            <td>
                                {{ $delivery->sender?->name }}
                                <div class="small text-muted">{{ $delivery->sender_office }}</div>
                            </td>
                            <td>
                                {{ $delivery->recipient?->name }}
                                <div class="small text-muted">{{ $delivery->recipient_office }}</div>
                            </td>
                            <td class="text-right text-nowrap">
                                @if ($delivery->status === \App\Enums\MovementStatus::Acknowledged)
                                    <span class="text-success"><i class="fas fa-check mr-1" aria-hidden="true"></i>Received</span>
                                    <div class="small text-muted">{{ $delivery->acknowledged_at?->format('d M Y H:i') }}</div>
                                @elseif ($delivery->delivered_at)
                                    <span class="text-info">Delivered; awaiting acknowledgement</span>
                                    <div class="small text-muted">{{ $delivery->delivered_at->format('d M Y H:i') }}</div>
                                @elseif ($delivery->picked_up_at)
                                    <span class="text-info"><i class="fas fa-truck mr-1" aria-hidden="true"></i>Picked up; not delivered</span>
                                    <div class="small text-muted">{{ $delivery->picked_up_at->format('d M Y H:i') }}</div>
                                    <a class="btn btn-sm btn-outline-primary mt-1" href="{{ route('movements.custody') }}">My Custody</a>
                                @else
                                    <button type="button" class="btn btn-sm btn-outline-primary" wire:click="openPickup({{ $delivery->id }})" wire:loading.attr="disabled">
                                        <i class="fas fa-truck mr-1" aria-hidden="true"></i>Confirm pickup
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">{{ match ($statusFilter) {
                                'completed' => 'You have no completed deliveries.',
                                'awaiting-pickup' => 'No deliveries are awaiting pickup.',
                                'in-transit' => 'No deliveries are in transit.',
                                'awaiting-receipt' => 'No deliveries are awaiting acknowledgement.',
                                default => 'No deliveries are currently assigned to you.',
                            } }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $deliveries->links() }}</div>

    @if ($pickupMovement)
        <div class="modal d-block" tabindex="-1" role="dialog" aria-modal="true"
             aria-labelledby="pickup-title" aria-describedby="pickup-question"
             wire:key="pickup-dialog-{{ $pickupMovement->id }}"
             x-data x-init="$nextTick(() => $refs.cancelPickup.focus())"
             x-on:keydown.escape.stop.prevent="$wire.closePickup()"
             style="background: rgba(0, 0, 0, .5);">
            <div class="modal-dialog modal-dialog-centered" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="pickup-title">Confirm Pickup</h5>
                    </div>
                    <div class="modal-body">
                        <p class="mb-2 text-break"><strong>{{ $pickupMovement->document->reference_number }}</strong>: {{ $pickupMovement->document->title }}</p>
                        <dl class="row mb-3">
                            <dt class="col-sm-4">Collect from</dt>
                            <dd class="col-sm-8 text-break">{{ $pickupMovement->sender?->name }}</dd>
                            <dt class="col-sm-4">Deliver to</dt>
                            <dd class="col-sm-8 text-break">{{ $pickupMovement->recipient?->name }}</dd>
                        </dl>
                        <p class="font-weight-bold mb-0" id="pickup-question">Do you confirm that you have physically picked up this document?</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" x-ref="cancelPickup" wire:click="closePickup" wire:loading.attr="disabled">Cancel</button>
                        <button type="button" class="btn btn-primary" wire:click="confirmPickup({{ $pickupMovement->id }})" wire:loading.attr="disabled">
                            <i class="fas fa-check mr-1" aria-hidden="true"></i>Confirm Pickup
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>