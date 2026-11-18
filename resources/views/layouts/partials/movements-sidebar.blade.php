@php($canViewDeliveries = app(\App\Services\Documents\CustodyService::class)->canViewDeliveries(auth()->user()))
@if ($canViewDeliveries || auth()->user()->canAny(['documents.register', 'documents.acknowledge', 'documents.forward']))
    @php($counts = app(\App\Services\Documents\WorkCounts::class)->for(auth()->user()))
    <li class="nav-header text-uppercase" style="color: {{ config('theme.color_accent') }}">Document Tracking</li>
    @can('documents.acknowledge')
        <li class="nav-item">
            <a href="{{ route('movements.pending') }}" class="nav-link {{ request()->routeIs('movements.pending') ? 'active' : '' }}">
                <i class="nav-icon fas fa-inbox"></i><p>Pending Acknowledgements
                    @include('layouts.partials.sidebar-count', ['count' => $counts['pending'], 'label' => 'waiting for you'])</p>
            </a>
        </li>
    @endcan
    @can('documents.forward')
        <li class="nav-item">
            <a href="{{ route('movements.custody') }}" class="nav-link {{ request()->routeIs('movements.custody') ? 'active' : '' }}">
                <i class="nav-icon fas fa-folder"></i><p>My Custody
                    @include('layouts.partials.sidebar-count', ['count' => $counts['custody'], 'label' => 'in your custody'])</p>
            </a>
        </li>
    @endcan
    @can('documents.register')
        <li class="nav-item">
            <a href="{{ route('movements.track') }}" class="nav-link {{ request()->routeIs('movements.track') ? 'active' : '' }}">
                <i class="nav-icon fas fa-map-marker-alt"></i><p>Track My Documents</p>
            </a>
        </li>
    @endcan
    @if ($canViewDeliveries)
        <li class="nav-item">
            <a href="{{ route('movements.deliveries') }}" class="nav-link {{ request()->routeIs('movements.deliveries') ? 'active' : '' }}">
                <i class="nav-icon fas fa-truck"></i><p>My Deliveries
                    @include('layouts.partials.sidebar-count', ['count' => $counts['deliveries'], 'label' => 'to deliver'])</p>
            </a>
        </li>
    @endif
@endif
