<div class="nav-item dropdown" wire:poll.60s="refresh">
    <a class="nav-link position-relative" data-toggle="dropdown" href="#" role="button" title="Notifications">
        <i class="far fa-bell"></i>
        @if($unreadCount > 0)
            <span class="badge badge-danger navbar-badge">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
        @endif
    </a>
    <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right" style="min-width: 320px;">
        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
            <span class="dropdown-item-title mb-0"><strong>Notifications</strong></span>
            @if($unreadCount > 0)
                <button type="button" class="btn btn-link btn-sm p-0" wire:click="markAllAsRead">
                    Mark all read
                </button>
            @endif
        </div>

        @forelse($recent as $n)
            <a href="{{ $n['url'] }}"
               wire:click.prevent="markAsRead('{{ $n['id'] }}')"
               onclick="setTimeout(() => window.location.href='{{ $n['url'] }}', 150)"
               class="dropdown-item {{ $n['unread'] ? 'bg-light' : '' }}">
                <div class="d-flex align-items-start">
                    <i class="{{ $n['icon'] }} mr-2 mt-1"></i>
                    <div class="flex-grow-1" style="white-space: normal;">
                        <div class="font-weight-bold small">
                            {{ $n['title'] }}
                            @if($n['unread'])
                                <span class="badge badge-primary badge-pill ml-1" style="font-size:0.6rem;">new</span>
                            @endif
                        </div>
                        <div class="text-muted small">{{ $n['message'] }}</div>
                        <div class="text-muted" style="font-size:0.7rem;">{{ $n['time'] }}</div>
                    </div>
                </div>
            </a>
            <div class="dropdown-divider m-0"></div>
        @empty
            <div class="dropdown-item text-center text-muted small py-3">
                No notifications yet.
            </div>
        @endforelse

        <a href="{{ route('notifications.index') }}" class="dropdown-item dropdown-footer">See all notifications</a>
        <a href="{{ route('settings.notifications') }}" class="dropdown-item dropdown-footer text-muted small">
            <i class="fas fa-cog"></i> Notification preferences
        </a>
    </div>
</div>
