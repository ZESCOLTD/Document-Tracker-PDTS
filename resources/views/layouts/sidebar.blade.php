<aside class="main-sidebar sidebar-dark-primary elevation-4"
       style="background: {{ config('theme.color_primary') }}">
    <a href="{{ route('dashboard') }}" class="brand-link" style="background: {{ config('theme.color_accent') }}">
        <img src="{{ asset('assets-dashboard/dist/img/logo/zesco_logo.png') }}"
             alt="Logo"
             class="brand-image img-circle elevation-3"
             style="opacity: .8">
        <span class="brand-text font-weight-light">{{ config('app.name') }}</span>
    </a>

    <div class="sidebar">
        <nav class="mt-2">
            <ul class="nav nav-pills nav-sidebar nav-child-indent flex-column"
                data-widget="treeview"
                role="menu"
                data-accordion="false">
                @can('dashboard.view')
                    <li class="nav-item">
                        <a href="{{ route('dashboard') }}" class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                            <i class="nav-icon fas fa-tachometer-alt"></i><p>Dashboard</p>
                        </a>
                    </li>
                @endcan

                @canany(['documents.view', 'documents.register', 'ai.ocr.use'])
                    <li class="nav-header text-uppercase" style="color: {{ config('theme.color_accent') }}">DOCUMENTS</li>
                    @can('documents.register')
                        <li class="nav-item">
                            <a href="{{ route('pdts.documents.register') }}" class="nav-link {{ request()->routeIs('pdts.documents.register') ? 'active' : '' }}">
                                <i class="nav-icon fas fa-file-signature"></i><p>Register Document</p>
                            </a>
                        </li>
                    @endcan
                    @can('documents.view')
                        <li class="nav-item">
                            <a href="{{ route('pdts.documents.index') }}" class="nav-link {{ request()->routeIs('pdts.documents.index', 'pdts.documents.show') ? 'active' : '' }}">
                                <i class="nav-icon fas fa-folder-open"></i><p>Registered Documents</p>
                            </a>
                        </li>
                    @endcan
                    @can('ai.ocr.use')
                        <li class="nav-item">
                            <a href="{{ route('pdts.ai.ocr') }}" class="nav-link {{ request()->routeIs('pdts.ai.ocr') ? 'active' : '' }}">
                                <i class="nav-icon fas fa-file-alt"></i><p>Document OCR Review</p>
                            </a>
                        </li>
                    @endcan
                @endcanany

                @include('layouts.partials.movements-sidebar')

                @php
                    $configPermissions = ['configuration.view', 'configuration.manage', 'document_types.manage', 'departments.manage', 'offices.manage', 'employee_assignments.manage', 'delegations.manage'];
                    $configOpen = request()->routeIs('configurations.*', 'pdts.organization.*');
                @endphp

                @canany(['users.view', ...$configPermissions])
                    <li class="nav-header text-uppercase" style="color: {{ config('theme.color_accent') }}">SETTINGS</li>
                @endcanany

                @can('users.view')
                    <li class="nav-item">
                        <a href="{{ route('users.index') }}" class="nav-link {{ request()->routeIs('users.*') ? 'active' : '' }}">
                            <i class="nav-icon fas fa-users-cog"></i><p>User Management</p>
                        </a>
                    </li>
                @endcan

                @auth
                    @php
                        $notifOpen = request()->routeIs('notifications.*')
                            || request()->routeIs('settings.notifications')
                            || request()->routeIs('admin.email_log');
                        $canSeeEmailLog = auth()->user()->can('admin.email_log.view');
                    @endphp
                    <li class="nav-item {{ $notifOpen ? 'menu-open' : '' }}">
                        <a href="#" class="nav-link {{ $notifOpen ? 'active' : '' }}">
                            <i class="nav-icon far fa-bell"></i>
                            <p>Email Notifications <i class="right fas fa-angle-left"></i></p>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item">
                                <a href="{{ route('notifications.index') }}" class="nav-link {{ request()->routeIs('notifications.index') ? 'active' : '' }}">
                                    <i class="far fa-dot-circle nav-icon"></i><p>My Notifications</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="{{ route('settings.notifications') }}" class="nav-link {{ request()->routeIs('settings.notifications') ? 'active' : '' }}">
                                    <i class="far fa-dot-circle nav-icon"></i><p>My Preferences</p>
                                </a>
                            </li>
                            @if($canSeeEmailLog)
                                <li class="nav-item">
                                    <a href="{{ route('admin.email_log') }}" class="nav-link {{ request()->routeIs('admin.email_log') ? 'active' : '' }}">
                                        <i class="far fa-dot-circle nav-icon text-warning"></i><p>Email Audit Log</p>
                                    </a>
                                </li>
                            @endif
                        </ul>
                    </li>
                @endauth

                @canany($configPermissions)
                    <li class="nav-item {{ $configOpen ? 'menu-open' : '' }}">
                        <a href="#" class="nav-link {{ $configOpen ? 'active' : '' }}">
                            <i class="nav-icon fas fa-cogs"></i>
                            <p>Configurations <i class="right fas fa-angle-left"></i></p>
                        </a>
                        <ul class="nav nav-treeview">
                            @can('configuration.manage')
                                <li class="nav-item">
                                    <a href="{{ route('configurations.roles.index') }}" class="nav-link {{ request()->routeIs('configurations.roles.*') ? 'active' : '' }}">
                                        <i class="far fa-dot-circle nav-icon"></i><p>Roles and Permissions</p>
                                    </a>
                                </li>
                            @endcan
                            @can('document_types.manage')
                                <li class="nav-item">
                                    <a href="{{ route('pdts.organization.document-types') }}" class="nav-link {{ request()->routeIs('pdts.organization.document-types') ? 'active' : '' }}">
                                        <i class="nav-icon fas fa-tags"></i><p>Document Types</p>
                                    </a>
                                </li>
                            @endcan
                            @can('departments.manage')
                                <li class="nav-item">
                                    <a href="{{ route('pdts.organization.departments') }}" class="nav-link {{ request()->routeIs('pdts.organization.departments') ? 'active' : '' }}">
                                        <i class="nav-icon fas fa-sitemap"></i><p>Departments</p>
                                    </a>
                                </li>
                            @endcan
                            @can('offices.manage')
                                <li class="nav-item">
                                    <a href="{{ route('pdts.organization.offices') }}" class="nav-link {{ request()->routeIs('pdts.organization.offices') ? 'active' : '' }}">
                                        <i class="nav-icon fas fa-building"></i><p>Offices</p>
                                    </a>
                                </li>
                            @endcan
                            @can('employee_assignments.manage')
                                <li class="nav-item">
                                    <a href="{{ route('pdts.organization.assignments') }}" class="nav-link {{ request()->routeIs('pdts.organization.assignments') ? 'active' : '' }}">
                                        <i class="nav-icon fas fa-user-tie"></i><p>Employee Assignments</p>
                                    </a>
                                </li>
                            @endcan
                            @can('delegations.manage')
                                <li class="nav-item">
                                    <a href="{{ route('pdts.organization.delegations') }}" class="nav-link {{ request()->routeIs('pdts.organization.delegations') ? 'active' : '' }}">
                                        <i class="nav-icon fas fa-user-shield"></i><p>Acknowledgement Delegations</p>
                                    </a>
                                </li>
                            @endcan
                        </ul>
                    </li>
                @endcanany
            </ul>
        </nav>
    </div>
</aside>
