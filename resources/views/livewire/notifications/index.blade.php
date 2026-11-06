<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <div>
            <h3 class="font-weight-bold mb-1">Notifications</h3>
            <p class="text-muted mb-0">All in-app notifications for your account.</p>
        </div>
        <div class="btn-group btn-group-sm" role="group">
            <button type="button" class="btn {{ $filter==='all' ? 'btn-primary' : 'btn-outline-primary' }}"
                    wire:click="$set('filter','all')">All</button>
            <button type="button" class="btn {{ $filter==='unread' ? 'btn-primary' : 'btn-outline-primary' }}"
                    wire:click="$set('filter','unread')">Unread</button>
            <button type="button" class="btn {{ $filter==='read' ? 'btn-primary' : 'btn-outline-primary' }}"
                    wire:click="$set('filter','read')">Read</button>
            <button type="button" class="btn btn-outline-secondary" wire:click="markAllAsRead">
                <i class="fas fa-check-double"></i> Mark all read
            </button>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            @forelse($notifications as $n)
                @php
                    $data = $n->data ?? [];
                    $unread = is_null($n->read_at);
                @endphp
                <div class="d-flex align-items-start p-3 border-bottom {{ $unread ? 'bg-light' : '' }}">
                    <i class="{{ $data['icon'] ?? 'fas fa-bell text-secondary' }} mr-3 mt-1" style="font-size:1.2rem;"></i>
                    <div class="flex-grow-1">
                        <div class="font-weight-bold">
                            {{ $data['title'] ?? 'Notification' }}
                            @if($unread)
                                <span class="badge badge-primary badge-pill ml-1">new</span>
                            @endif
                        </div>
                        <div class="text-muted small">{{ $data['message'] ?? '' }}</div>
                        <div class="text-muted" style="font-size:0.75rem;">{{ $n->created_at?->diffForHumans() }}</div>
                    </div>
                    <div class="ml-3">
                        @if(!empty($data['url']))
                            <a href="{{ $data['url'] }}"
                               wire:click="markAsRead('{{ $n->id }}')"
                               class="btn btn-sm btn-outline-primary">
                                Open
                            </a>
                        @endif
                        @if($unread)
                            <button class="btn btn-sm btn-outline-secondary" wire:click="markAsRead('{{ $n->id }}')">
                                Mark read
                            </button>
                        @endif
                    </div>
                </div>
            @empty
                <div class="text-center text-muted py-5">
                    <i class="far fa-bell-slash fa-3x mb-2"></i>
                    <div>No notifications to show.</div>
                </div>
            @endforelse
        </div>
        <div class="card-footer">
            {{ $notifications->links() }}
        </div>
    </div>
</div>
