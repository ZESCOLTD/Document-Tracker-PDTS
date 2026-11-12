<div class="nav-item dropdown bell-wrap" wire:poll.60s="refresh">
    <style>
        .bell-wrap .bell-btn {
            position: relative; width: 2.25rem; height: 2.25rem; padding: 0; margin: 0 .25rem;
            display: inline-flex; align-items: center; justify-content: center;
            border-radius: 50%; color: #495057; background: transparent; border: 0;
            transition: background-color .15s ease, color .15s ease;
        }
        .bell-wrap .bell-btn:hover,
        .bell-wrap .bell-btn[aria-expanded="true"] {
            background: #e9f5ee; color: {{ config('theme.color_sidebar') }};
        }
        .bell-wrap .bell-btn i { font-size: 1.1rem; }
        .bell-wrap .bell-btn.has-unread i { animation: bell-ring 3s ease-in-out infinite; transform-origin: 50% 0; }
        @keyframes bell-ring {
            0%, 78%, 100% { transform: rotate(0); }
            82% { transform: rotate(14deg); }
            86% { transform: rotate(-12deg); }
            90% { transform: rotate(8deg); }
            94% { transform: rotate(-4deg); }
        }
        .bell-wrap .bell-count {
            position: absolute; top: 0; right: -2px; min-width: 1.15rem; height: 1.15rem; padding: 0 .3rem;
            border-radius: 999px; background: {{ config('theme.color_accent') }}; color: #fff;
            font-size: .65rem; font-weight: 700; line-height: 1.15rem; text-align: center;
            box-shadow: 0 0 0 2px #fff;
        }
        .bell-wrap .dropdown-menu {
            width: 360px; padding: 0; border: 0; border-radius: .75rem; overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, .15);
        }
        .bell-wrap .bell-head {
            display: flex; justify-content: space-between; align-items: center;
            padding: .75rem 1rem; background: {{ config('theme.color_sidebar') }}; color: #fff;
        }
        .bell-wrap .bell-head .btn-link { color: #fff; font-size: .8rem; text-decoration: underline; opacity: .9; }
        .bell-wrap .bell-item {
            display: flex; gap: .75rem; padding: .75rem 1rem; white-space: normal;
            border-left: 3px solid transparent; border-bottom: 1px solid #f1f1f1;
        }
        .bell-wrap .bell-item:hover { background: #eef6f1; }
        .bell-wrap .bell-item.is-unread { background: #f3faf6; border-left-color: {{ config('theme.color_sidebar') }}; }
        .bell-wrap .bell-icon {
            width: 2.1rem; height: 2.1rem; flex-shrink: 0; border-radius: 50%;
            display: flex; align-items: center; justify-content: center; background: #e9f5ee;
        }
        .bell-wrap .bell-empty { padding: 2rem 1rem; text-align: center; color: #6c757d; }
        .bell-wrap .bell-empty i { font-size: 1.75rem; color: #c3d9cc; display: block; margin-bottom: .5rem; }
        .bell-wrap .bell-foot {
            display: block; padding: .6rem 1rem; text-align: center; font-size: .85rem;
            color: {{ config('theme.color_sidebar') }}; background: #f8faf9; font-weight: 600;
        }
        .bell-wrap .bell-foot:hover { background: #e9f5ee; color: {{ config('theme.color_sidebar') }}; }
        .bell-wrap .bell-foot.muted { color: #6c757d; font-weight: 400; font-size: .8rem; }
    </style>
    <button type="button" class="bell-btn {{ $unreadCount > 0 ? 'has-unread' : '' }}" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" title="Notifications">
        <i class="far fa-bell"></i>
        @if($unreadCount > 0)
            <span class="bell-count">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
        @endif
    </button>
    <div class="dropdown-menu dropdown-menu-right">
        <div class="bell-head">
            <strong>Notifications</strong>
            @if($unreadCount > 0)
                <button type="button" class="btn btn-link btn-sm p-0" wire:click="markAllAsRead">
                    Mark all read
                </button>
            @endif
        </div>

        @forelse($recent as $n)
            <a href="{{ $n['url'] }}"
               wire:click.prevent="open('{{ $n['id'] }}')"
               class="bell-item {{ $n['unread'] ? 'is-unread' : '' }}">
                <span class="bell-icon"><i class="{{ $n['icon'] }}"></i></span>
                <div class="flex-grow-1">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="font-weight-bold small text-dark">{{ $n['title'] }}</span>
                        @if($n['unread'])
                            <span class="badge badge-pill ml-1" style="background: {{ config('theme.color_accent') }}; color: #fff; font-size: .6rem;">new</span>
                        @endif
                    </div>
                    <div class="text-muted small">{{ $n['message'] }}</div>
                    <div class="text-muted" style="font-size: .7rem;">{{ $n['time'] }}</div>
                </div>
            </a>
        @empty
            <div class="bell-empty">
                <i class="far fa-bell-slash"></i>
                <div class="small">No notifications yet.</div>
            </div>
        @endforelse

        <a href="{{ route('notifications.index') }}" class="bell-foot">See all notifications</a>
        <a href="{{ route('settings.notifications') }}" class="bell-foot muted">
            <i class="fas fa-cog"></i> Notification preferences
        </a>
    </div>
</div>
