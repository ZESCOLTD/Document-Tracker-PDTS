<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">

<head>
    @include('partials.head')
</head>

<body class="min-h-screen overflow-hidden bg-[#f1f5f9] antialiased dark:bg-[#020617]">

<div class="relative grid h-dvh lg:grid-cols-2">

    {{-- ===================================================== --}}
    {{-- LEFT PROFESSIONAL BRANDING PANEL --}}
    {{-- ===================================================== --}}
    <div class="relative hidden overflow-hidden lg:flex">

        {{-- Background Image --}}
        <img
            src="{{ asset('assets-dashboard/img/login_bg22.png') }}"
            alt="Background"
            class="absolute inset-0 h-full w-full object-cover">

        {{-- PRIMARY + ACCENT OVERLAY --}}
        <div
            class="absolute inset-0 bg-gradient-to-br from-[#253745]/97 via-[#2e4354]/93 to-[#1e2d38]/95">
        </div>

        {{-- ORANGE AMBIENT GLOW --}}
        <div
            class="absolute -top-24 -left-20 h-[28rem] w-[28rem] rounded-full bg-[#f59d33]/20 blur-3xl">
        </div>

        {{-- PRIMARY COLOR GLOW --}}
        <div
            class="absolute bottom-0 right-0 h-96 w-96 rounded-full bg-[#253745]/35 blur-3xl">
        </div>

        {{-- SOFT ORANGE OVERLAY --}}
        <div
            class="absolute top-1/3 right-1/4 h-72 w-72 rounded-full bg-[#f59d33]/10 blur-3xl">
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

            {{-- ===================================================== --}}
            {{-- TOP BRANDING --}}
            {{-- ===================================================== --}}
            <div>

                {{-- Flat Logo --}}
                <div class="flex items-center gap-5">

                    <img
                        src="{{ asset('assets-dashboard/img/logo.png') }}"
                        alt="Logo"
                        class="h-16 w-auto object-contain">

                    <div>

                        <h1 class="text-3xl font-semibold tracking-wide text-white">
                            {{ config('app.name') }}
                        </h1>

                        <p class="mt-1 text-sm uppercase tracking-[0.25em] text-slate-200/80">
                            ZESCO Physical Document Tracking System
                        </p>

                    </div>

                </div>

                {{-- Elegant Divider --}}
                <div
                    class="mt-8 h-[2px] w-40 rounded-full bg-gradient-to-r from-[#f59d33] via-[#ffd089] to-transparent">
                </div>

            </div>

            {{-- ===================================================== --}}
            {{-- HERO CONTENT --}}
            {{-- ===================================================== --}}
            <div class="max-w-2xl">

                {{-- Badge --}}
                <div
                    class="mb-6 inline-flex items-center rounded-full border border-[#f59d33]/30 bg-[#f59d33]/10 px-5 py-2 text-sm font-medium text-[#ffd089] backdrop-blur-sm">

                    ZESCO PHYSICAL DOCUMENT TRACKING SYSTEM

                </div>

                {{-- Heading --}}
                <h2 class="text-5xl font-light leading-tight tracking-wide text-white">



                    <span class="font-semibold text-[#f59d33]">Physical Document</span>
                    Tracking System

                </h2>

                {{-- Description --}}
                <p class="mt-8 max-w-xl text-lg leading-8 text-slate-200/80">

                    Secure access to ZESCO's physical document tracking workspace.

                </p>

                {{-- ===================================================== --}}
                {{-- FEATURE HIGHLIGHTS --}}
                {{-- ===================================================== --}}
                <div class="mt-14 grid grid-cols-2 gap-6">

                    {{-- Feature 1 --}}
                    <div
                        class="rounded-2xl border border-white/10 bg-white/5 p-6 backdrop-blur-md transition duration-300 hover:bg-white/10">

                        <div
                            class="mb-4 inline-flex h-12 w-12 items-center justify-center rounded-xl bg-[#f59d33]/15 text-xl text-[#f59d33]">

                            📍

                        </div>

                        <div class="text-sm uppercase tracking-widest text-[#ffd089]">
                            Property Operations
                        </div>

{{--                        <div class="mt-2 text-lg font-semibold text-white">--}}
{{--                            Site & Property Monitoring--}}
{{--                        </div>--}}

{{--                        <p class="mt-3 text-sm leading-6 text-slate-200/70">--}}

{{--                            approvals, documentation, and site activities efficiently.--}}

{{--                        </p>--}}

                    </div>

                    {{-- Feature 2 --}}
                    <div
                        class="rounded-2xl border border-white/10 bg-white/5 p-6 backdrop-blur-md transition duration-300 hover:bg-white/10">

                        <div
                            class="mb-4 inline-flex h-12 w-12 items-center justify-center rounded-xl bg-[#253745]/40 text-xl text-[#d5dde3]">

                            🏗️

                        </div>

                        <div class="text-sm uppercase tracking-widest text-slate-300">
                            Projects &amp; Approvals
                        </div>

{{--                        <div class="mt-2 text-lg font-semibold text-white">--}}
{{--                            Project Coordination--}}
{{--                        </div>--}}

{{--                        <p class="mt-3 text-sm leading-6 text-slate-200/70">--}}

{{--                            Improve collaboration across departments,--}}
{{--                            field teams, approvals, and infrastructure projects.--}}

{{--                        </p>--}}

                    </div>

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
            class="absolute top-10 right-10 h-40 w-40 rounded-full bg-[#f59d33]/10 blur-3xl">
        </div>

        <div
            class="absolute bottom-10 left-10 h-52 w-52 rounded-full bg-[#253745]/20 blur-3xl">
        </div>

        {{-- LOGIN WRAPPER --}}
        <div class="relative z-10 w-full max-w-md">

            {{-- Mobile Logo --}}
            <div class="mb-10 flex flex-col items-center lg:hidden">

                <img
                    src="{{ asset('assets-dashboard/img/logo.png') }}"
                    alt="Logo"
                    class="h-16 w-auto object-contain">

                <h1 class="mt-5 text-3xl font-semibold tracking-wide text-slate-800 dark:text-white">
                    {{ config('app.name') }}
                </h1>

                <p class="mt-2 text-sm uppercase tracking-[0.25em] text-slate-500 dark:text-slate-400">
                    ZESCO Physical Document Tracking System
                </p>

            </div>

            {{-- LOGIN CARD --}}
            <div
                class="rounded-3xl border border-slate-200/70 bg-white/90 p-8 shadow-[0_25px_80px_rgba(15,23,42,0.10)] backdrop-blur-xl dark:border-slate-800 dark:bg-slate-900/85">

                {{-- Accent --}}
                <div class="mb-8 flex items-center gap-3">

                    <div class="h-1.5 w-14 rounded-full bg-[#253745]"></div>

                    <div class="h-1.5 w-6 rounded-full bg-[#f59d33]"></div>

                </div>

                {{-- Welcome --}}
                <div class="mb-8">

                    <h2 class="text-3xl font-semibold tracking-tight text-slate-800 dark:text-white">
                        Welcome Back
                    </h2>

                    <p class="mt-2 text-sm leading-6 text-slate-500 dark:text-slate-400">

                        Sign in to continue to PDTS.

                    </p>

                </div>

                {{-- LOGIN FORM SLOT --}}
                <div class="space-y-6">
                    {{ $slot }}
                </div>

            </div>

        </div>

    </div>

</div>

@fluxScripts

</body>

</html>
