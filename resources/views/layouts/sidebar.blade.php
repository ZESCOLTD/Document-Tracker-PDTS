<style>
    /* Sidebar colours: deep teal, white icons and headings, a lighter teal pill for the active item. */
    .main-sidebar.pdts-sidebar { background: linear-gradient(180deg, #093f42 0%, #072f32 100%) !important; }
    .pdts-sidebar .nav-sidebar .nav-icon { color: #fff !important; }
    .pdts-sidebar .nav-sidebar .nav-header {
        color: #fff !important; font-weight: 600; font-size: .78rem; letter-spacing: .06em;
        border-top: 1px solid #1e5e61; margin: .5rem .5rem 0; padding: .875rem .5rem .375rem;
    }
    /* No line above the first heading (straight after Dashboard), as in the design. */
    .pdts-sidebar .nav-sidebar > .nav-item:first-child + .nav-header,
    .pdts-sidebar .nav-sidebar > .nav-header:first-child { border-top: 0; }
    .pdts-sidebar .nav-sidebar .nav-link { border-radius: .5rem; position: relative; display: flex; align-items: center; }
    /* Labels sit beside their icon; a long one wraps to a second line, still beside the icon. */
    .pdts-sidebar .nav-sidebar .nav-link > p {
        flex: 1 1 auto; min-width: 0; margin: 0; font-size: .9375rem; line-height: 1.3; white-space: normal;
    }
    /* Room on the right for the count bubble. */
    .pdts-sidebar .nav-sidebar .nav-link:has(.pdts-count) > p { padding-right: 2rem; }
    .pdts-sidebar .nav-sidebar .nav-link > .nav-icon { flex-shrink: 0; }
    .pdts-sidebar .nav-sidebar .nav-link:hover:not(.active) { background-color: rgba(255, 255, 255, .07) !important; }
    /* Count bubble, e.g. Pending Acknowledgements 5. */
    .pdts-sidebar .nav-sidebar .pdts-count {
        position: absolute; right: .75rem; top: 50%; transform: translateY(-50%);
        min-width: 1.375rem; height: 1.375rem; padding: 0 .375rem; border-radius: 999px;
        display: inline-flex; align-items: center; justify-content: center;
        background: {{ config('theme.color_accent') }}; color: #fff; font-size: .75rem; font-weight: 700; line-height: 1;
        box-shadow: 0 1px 3px rgba(0, 0, 0, .25); font-variant-numeric: tabular-nums;
    }
    .sidebar-collapse .pdts-sidebar:not(:hover) .pdts-count { right: .25rem; top: .35rem; transform: none; min-width: 1rem; height: 1rem; font-size: .625rem; }
    .pdts-sidebar .nav-sidebar .nav-link.active {
        background-color: #218381 !important; color: #fff !important; box-shadow: 0 2px 6px rgba(0, 0, 0, .2);
    }
</style>
<aside class="main-sidebar pdts-sidebar sidebar-dark-primary elevation-4">
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
                        <li class="nav-item">
                            <a href="{{ route('pdts.documents.internal.create') }}" class="nav-link {{ request()->routeIs('pdts.documents.internal.create') ? 'active' : '' }}">
                                <i class="nav-icon fas fa-file-word"></i><p>New Internal Document</p>
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
                    $configPermissions = ['configuration.view', 'configuration.manage', 'document_types.manage', 'directorates.manage', 'departments.manage', 'offices.manage', 'employee_assignments.manage', 'delegations.manage'];
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
                                <li class="nav-item">
                                    <a href="{{ route('pdts.organization.document-templates') }}" class="nav-link {{ request()->routeIs('pdts.organization.document-templates') ? 'active' : '' }}">
                                        <i class="nav-icon fas fa-file-word"></i><p>Document Templates</p>
                                    </a>
                                </li>
                            @endcan
                            @can('directorates.manage')
                                <li class="nav-item">
                                    <a href="{{ route('pdts.organization.directorates') }}" class="nav-link {{ request()->routeIs('pdts.organization.directorates') ? 'active' : '' }}">
                                        <i class="nav-icon fas fa-landmark"></i><p>Directorates</p>
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
