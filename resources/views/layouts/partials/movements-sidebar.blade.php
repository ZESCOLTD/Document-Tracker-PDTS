@canany(['documents.register', 'documents.acknowledge', 'documents.forward'])
    <li class="nav-header text-uppercase" style="color: {{ config('theme.color_accent') }}">Document Tracking</li>
    @can('documents.acknowledge')
        <li class="nav-item">
            <a href="{{ route('movements.pending') }}" class="nav-link {{ request()->routeIs('movements.pending') ? 'active' : '' }}">
                <i class="nav-icon fas fa-inbox"></i><p>Pending Acknowledgements</p>
            </a>
        </li>
    @endcan
    @can('documents.forward')
        <li class="nav-item">
            <a href="{{ route('movements.custody') }}" class="nav-link {{ request()->routeIs('movements.custody') ? 'active' : '' }}">
                <i class="nav-icon fas fa-folder"></i><p>My Custody</p>
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
@endcanany
