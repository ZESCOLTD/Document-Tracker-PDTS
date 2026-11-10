@canany(['documents.register', 'documents.acknowledge', 'documents.forward'])
    @php($movementsOpen = request()->routeIs('movements.*'))
    <li class="nav-item doc-tracking-menu {{ $movementsOpen ? 'menu-open' : '' }}">
        <a href="#" class="nav-link">
            <i class="nav-icon fas fa-route"></i>
            <p>Document Tracking <i class="right fas fa-angle-left"></i></p>
        </a>
        <ul class="nav nav-treeview">
            @can('documents.acknowledge')
                <li class="nav-item">
                    <a href="{{ route('movements.pending') }}" class="nav-link {{ request()->routeIs('movements.pending') ? 'active' : '' }}">
                        <i class="far fa-dot-circle nav-icon"></i><p>Pending Acknowledgements</p>
                    </a>
                </li>
            @endcan
            @can('documents.forward')
                <li class="nav-item">
                    <a href="{{ route('movements.custody') }}" class="nav-link {{ request()->routeIs('movements.custody') ? 'active' : '' }}">
                        <i class="far fa-dot-circle nav-icon"></i><p>My Custody</p>
                    </a>
                </li>
            @endcan
            @can('documents.register')
                <li class="nav-item">
                    <a href="{{ route('movements.track') }}" class="nav-link {{ request()->routeIs('movements.track') ? 'active' : '' }}">
                        <i class="far fa-dot-circle nav-icon"></i><p>Track My Documents</p>
                    </a>
                </li>
            @endcan
        </ul>
    </li>
@endcanany
