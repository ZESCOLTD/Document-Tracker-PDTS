<style>
    .main-header.navbar { background: #fff; border-bottom: 1px solid {{ config('theme.color_sidebar') }}; box-shadow: 0 2px 8px rgba(0, 0, 0, .08); min-height: 3.5rem; }
    .main-header.navbar.no-accent-line { border-bottom: 0; }
    .main-header .navbar-nav > .nav-item > .nav-link { color: #343a40; border-radius: .375rem; transition: color .15s, background-color .15s; }
    .main-header .navbar-nav > .nav-item > .nav-link:hover { color: {{ config('theme.color_sidebar') }}; background: rgba(7, 91, 53, .08); }
    .main-header .navbar-nav > .nav-item > a[data-widget="pushmenu"] { font-size: 1.1rem; }
    .main-header .app-name { font-weight: 700; color: {{ config('theme.color_sidebar') }}; }
    .main-header .user-menu > .nav-link { gap: .5rem; padding: .25rem .75rem; }
    .main-header .user-menu > .nav-link span { font-weight: 600; color: #212529; }
    .main-header .user-image { width: 32px; height: 32px; object-fit: cover; border: 2px solid {{ config('theme.color_sidebar') }}; }
    .main-header .user-menu .dropdown-menu { border: 0; border-radius: .5rem; box-shadow: 0 10px 30px rgba(0, 0, 0, .15); overflow: hidden; padding: 0; min-width: 260px; }
    .main-header .user-header { background: {{ config('theme.color_sidebar') }} !important; }
    .main-header .user-header img { border: 3px solid rgba(255, 255, 255, .85); }
    .main-header .user-menu .card-footer { background: #f8f9fa; border-top: 1px solid #e9ecef; }
    .main-header .user-menu .card-footer .btn-flat { border: 1px solid {{ config('theme.color_sidebar') }}; color: {{ config('theme.color_sidebar') }}; border-radius: .375rem; }
    .main-header .user-menu .card-footer .btn-flat:hover { background: {{ config('theme.color_sidebar') }}; color: #fff; }
</style>

<nav class="main-header navbar navbar-expand navbar-white navbar-light {{ request()->routeIs('dashboard') ? 'no-accent-line' : '' }}">
    <!-- Left navbar links -->
    <ul class="navbar-nav">
        <li class="nav-item">
            <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
        </li>
        <li class="nav-item d-none d-sm-inline-block">
            <a href="{{route('home')}}" class="nav-link app-name">{{config('app.name')}}</a>
        </li>

    </ul>



    <!-- Right navbar links -->
    <ul class="navbar-nav ml-auto">

        @auth
            @livewire('notifications.bell')
        @endauth

        <!-- User Account: style can be found in dropdown.less -->
        <li class="nav-item dropdown  user-menu">
            <a href="#" class="nav-link" data-toggle="dropdown">
                <img src="{{ asset('assets-dashboard/dist/img/face/face-0.jpg') }}" class="user-image" alt="User Image">
                <span class="hidden-xs">{{Auth::user()->getFullnameAttribute() ?? ""}}</span>
            </a>
            <ul class="dropdown-menu">
                <!-- User image -->
                <li class="user-header bg-gradient-green" style="height: auto">
                    <img src="{{ asset('assets-dashboard/dist/img/face/face-0.jpg') }}" class="img-circle" alt="User Image">

                    <p>
                        {{Auth::user()->getFullnameAttribute() ?? ""}}
                        <br> {{Auth::user()->job_title ?? ""}}
                        <br> {{Auth::user()->grade ?? ""}}
                    </p>

                </li>
                <!-- Menu Body -->
                <!-- Menu Footer-->
                <li class="card-footer">
                    {{--                    <a href="#" class="btn btn-default btn-flat float-left">Profile</a>--}}

                    <a href="{{ route('logout') }}" class="btn btn-default btn-flat float-right"
                       onclick="event.preventDefault();  document.getElementById('logout-form').submit();">
                        {{ __('Logout') }}
                    </a>
                    <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
                        @csrf
                    </form>

                </li>
            </ul>
        </li>
        <!-- Control Sidebar Toggle Button -->


    </ul>
</nav>
