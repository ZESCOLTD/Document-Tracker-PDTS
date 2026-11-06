<nav class="main-header navbar navbar-expand navbar-white navbar-light ">
    <!-- Left navbar links -->
    <ul class="navbar-nav">
        <li class="nav-item">
            <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
        </li>
        <li class="nav-item d-none d-sm-inline-block">
            <a href="{{route('home')}}" class="nav-link">{{config('app.name')}}</a>
        </li>

    </ul>



    <!-- Right navbar links -->
    <ul class="navbar-nav ml-auto">

        {{-- Notifications bell (Livewire) — TEMPORARILY DISABLED
             Re-enable by uncommenting the block below once the shared
             livewire:init PublicPropertyNotFoundException is resolved. --}}
        {{--
        @auth
            @livewire('notifications.bell')
        @endauth
        --}}

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
