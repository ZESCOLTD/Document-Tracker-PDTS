<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    @include('partials.head')
</head>

<body class="min-h-screen overflow-hidden bg-slate-100 antialiased dark:bg-slate-950">

<div class="relative grid h-dvh lg:grid-cols-2">

    {{-- ===================================================== --}}
    {{-- LEFT PROFESSIONAL BRANDING PANEL --}}
    {{-- ===================================================== --}}
    <div class="relative hidden overflow-hidden lg:flex">

        {{-- Office photo --}}
        <img
            src="{{ asset('assets-dashboard/dist/img/login/office-photo.png') }}"
            alt=""
            class="absolute inset-0 h-full w-full object-cover">

        {{-- ZESCO green overlay --}}
        <div
            class="absolute inset-0 bg-gradient-to-br from-[#0a4a28]/95 via-[#136b3a]/90 to-[#0a3920]/95">
        </div>

        {{-- Orange glow --}}
        <div
            class="absolute -bottom-32 -left-20 h-[28rem] w-[28rem] rounded-full bg-[#f39c34]/25 blur-3xl">
        </div>

        {{-- Green glow --}}
        <div
            class="absolute -top-24 right-0 h-96 w-96 rounded-full bg-[#1a9650]/30 blur-3xl">
        </div>

        {{-- GRID OVERLAY --}}
        <div
            class="absolute inset-0 opacity-[0.04]"
            style="background-image:
                linear-gradient(to right, white 1px, transparent 1px),
                linear-gradient(to bottom, white 1px, transparent 1px);
                background-size: 40px 40px;">
        </div>

        {{-- CONTENT --}}
        <div class="relative z-20 flex h-full w-full flex-col justify-between p-16 text-white">

            {{-- Top branding: ZESCO logo on white plate for contrast --}}
            <div class="flex items-center gap-5">

                <div class="flex items-center justify-center rounded-2xl bg-white px-5 py-3 shadow-xl">
                    <img
                        src="{{ asset('assets-dashboard/dist/img/logo/ZESCO_removebg.png') }}"
                        alt="ZESCO"
                        class="h-14 w-auto object-contain">
                </div>

                <div>
                    <p class="text-2xl font-bold tracking-wide text-white">PDTS</p>
                    <p class="text-xs uppercase tracking-[0.25em] text-emerald-100/80">ZESCO</p>
                </div>

            </div>

            {{-- Hero --}}
            <div class="max-w-2xl">

                <h2 class="text-5xl font-bold leading-tight tracking-tight text-white">
                    Physical Document<br>
                    <span class="text-[#f39c34]">Tracking System</span>
                </h2>

                <div class="mt-6 h-1 w-24 rounded-full bg-[#f39c34]"></div>

                <p class="mt-6 max-w-xl text-lg leading-8 text-emerald-50/90">
                    Track, manage and monitor the movement of physical documents across the organisation.
                </p>

            </div>

            {{-- Feature highlights --}}
            <div class="grid grid-cols-3 divide-x divide-white/20 text-center">

                <div class="flex flex-col items-center gap-3 px-4">
                    <svg class="h-9 w-9 text-[#f39c34]" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                    </svg>
                    <span class="text-sm font-medium text-white">Register Documents</span>
                </div>

                <div class="flex flex-col items-center gap-3 px-4">
                    <svg class="h-9 w-9 text-[#f39c34]" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" />
                    </svg>
                    <span class="text-sm font-medium text-white">Forward &amp; Acknowledge</span>
                </div>

                <div class="flex flex-col items-center gap-3 px-4">
                    <svg class="h-9 w-9 text-[#f39c34]" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
                    </svg>
                    <span class="text-sm font-medium text-white">Track to Completion</span>
                </div>

            </div>

        </div>

    </div>

    {{-- ===================================================== --}}
    {{-- RIGHT LOGIN SECTION --}}
    {{-- ===================================================== --}}
    <div class="relative flex items-center justify-center overflow-hidden px-6 py-10 lg:px-20">

        {{-- Decorative Background Elements --}}
        <div
            class="absolute top-10 right-10 h-40 w-40 rounded-full bg-[#f39c34]/10 blur-3xl">
        </div>

        <div
            class="absolute bottom-10 left-10 h-52 w-52 rounded-full bg-[#1a9650]/15 blur-3xl">
        </div>

        {{-- LOGIN WRAPPER --}}
        <div class="relative z-10 w-full max-w-md">

            {{-- Mobile Logo --}}
            <div class="mb-10 flex flex-col items-center lg:hidden">

                <div class="flex items-center justify-center rounded-2xl bg-white px-5 py-3 shadow-md">
                    <img
                        src="{{ asset('assets-dashboard/dist/img/logo/ZESCO_removebg.png') }}"
                        alt="ZESCO"
                        class="h-14 w-auto object-contain">
                </div>

                <h1 class="mt-5 text-3xl font-bold tracking-wide text-[#0f5f33] dark:text-emerald-300">
                    PDTS
                </h1>

                <p class="mt-2 text-sm uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">
                    Physical Document Tracking System
                </p>

            </div>

            {{-- LOGIN CARD --}}
            <div
                class="relative overflow-hidden rounded-3xl border border-slate-200 bg-white p-8 shadow-[0_25px_80px_rgba(15,23,42,0.10)] sm:p-10 dark:border-slate-800 dark:bg-slate-900 dark:shadow-none">

                {{-- Card header (desktop) --}}
                <div class="mb-8 hidden flex-col items-center text-center lg:flex">

                    <div class="flex items-center justify-center rounded-2xl bg-white px-5 py-3 shadow-md">
                        <img
                            src="{{ asset('assets-dashboard/dist/img/logo/ZESCO_removebg.png') }}"
                            alt="ZESCO"
                            class="h-16 w-auto object-contain">
                    </div>

                    <h1 class="mt-4 text-3xl font-extrabold tracking-wide text-[#0f5f33] dark:text-emerald-300">
                        PDTS
                    </h1>

                    <p class="mt-1 text-xs uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">
                        Physical Document Tracking System
                    </p>

                </div>

                {{-- Welcome --}}
                <div class="mb-8">

                    <h2 class="text-2xl font-semibold tracking-tight text-slate-800 dark:text-white">
                        Sign In to Your Account
                    </h2>

                    <p class="mt-2 text-sm leading-6 text-slate-500 dark:text-slate-400">
                        Sign in to continue to PDTS.
                    </p>

                </div>

                {{-- LOGIN FORM SLOT --}}
                <div class="relative z-10 space-y-6">
                    {{ $slot }}
                </div>

                {{-- Document stack decoration --}}
                <img
                    src="{{ asset('assets-dashboard/dist/img/login/document-stack.png') }}"
                    alt=""
                    aria-hidden="true"
                    class="pointer-events-none absolute -bottom-6 -right-6 w-44 opacity-20">

            </div>

        </div>

    </div>

</div>

@fluxScripts

</body>

</html>
