<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? 'PDTS' }}</title>
    <link rel="stylesheet" href="{{ asset('assets-dashboard/plugins/fontawesome-free/css/all.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets-dashboard/dist/css/adminlte.min.css') }}">
    <style>
        :root { --pdts-green: {{ config('theme.color_sidebar', '#093f42') }}; --pdts-orange: {{ config('theme.color_accent', '#ca7533') }}; }
        body { background: #f2f4f3; font-size: 1rem; }
        .scan-bar { background: var(--pdts-green); color: #fff; }
        .scan-bar img { width: 34px; height: 34px; border-radius: 50%; background: #fff; padding: 2px; }
        .scan-wrap { max-width: 560px; margin: 0 auto; padding: 1rem .875rem 2.5rem; }
        .btn-primary { background: var(--pdts-orange) !important; border-color: var(--pdts-orange) !important; color: #fff !important; }
        .btn-success { background: var(--pdts-green) !important; border-color: var(--pdts-green) !important; }
        .btn-lg { padding: .875rem 1rem; font-size: 1.125rem; font-weight: 600; }
    </style>
    @livewireStyles
</head>
<body>
    <header class="scan-bar">
        <div class="scan-wrap d-flex align-items-center py-2" style="padding-bottom: .5rem; gap: .625rem;">
            <img src="{{ asset('assets-dashboard/dist/img/logo/zesco_logo.png') }}" alt="ZESCO">
            <span class="font-weight-bold" style="font-size: 1.125rem;">PDTS</span>
            <span class="ml-auto small text-right" style="opacity: .9;">{{ auth()->user()?->name }}</span>
            @auth
                <form method="POST" action="{{ request()->route('token') ? route('pdts.scan.logout', request()->route('token')) : route('logout') }}" class="mb-0">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-light ml-2" style="white-space: nowrap;">
                        <i class="fas fa-sign-out-alt mr-1"></i>Log out
                    </button>
                </form>
            @endauth
        </div>
    </header>

    <main class="scan-wrap">
        {{ $slot }}
    </main>

    <script src="{{ asset('assets-dashboard/plugins/jquery/jquery.min.js') }}"></script>
    <script src="{{ asset('assets-dashboard/plugins/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    @livewireScripts
</body>
</html>
