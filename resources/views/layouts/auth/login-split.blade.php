<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    @include('partials.head')
    {{-- Auth pages render light regardless of the OS theme Flux applies. --}}
    <script>document.documentElement.classList.remove('dark');</script>
</head>

<body class="min-h-dvh overflow-x-hidden bg-[#00452B] font-sans antialiased">

<main class="relative isolate min-h-dvh lg:h-dvh lg:overflow-hidden">

    {{-- FULL-SCREEN OFFICE BACKGROUND --}}
    <img
        src="{{ asset('assets-dashboard/dist/img/login/office-photo.png') }}"
        alt=""
        aria-hidden="true"
        class="absolute inset-0 -z-20 h-full w-full object-cover object-left">

    <div class="absolute inset-0 -z-10 bg-gradient-to-r from-[#00452B]/65 via-[#075B35]/40 to-[#075B35]/25"></div>

    {{-- LEFT: BRANDING AND INTRODUCTION --}}
    <section class="relative z-10 flex flex-col gap-10 px-6 pb-12 pt-8 text-white sm:px-10 lg:absolute lg:bottom-[8.5%] lg:left-[10%] lg:right-[48%] lg:top-[8.5%] lg:justify-between lg:gap-0 lg:px-0 lg:py-0">

        <div class="flex items-center gap-4">

            <div class="flex items-center rounded-xl bg-white px-3 py-2 shadow-lg">
                <img
                    src="{{ asset('assets-dashboard/img/logo.png') }}"
                    alt="ZESCO"
                    class="h-20 w-auto object-contain">
            </div>

            <span class="h-12 w-px bg-white/30" aria-hidden="true"></span>

            <div class="flex items-center gap-3">
                <svg class="h-10 w-10 shrink-0" viewBox="0 0 64 64" fill="none" aria-hidden="true">
                    <rect x="12" y="6" width="34" height="42" rx="4" fill="#ffffff" fill-opacity="0.15" stroke="#ffffff" stroke-width="3"/>
                    <path d="M22 20h14M22 28h14M22 36h9" stroke="#ffffff" stroke-width="3" stroke-linecap="round"/>
                    <path d="M8 54c12 6 32 6 48-6" stroke="#F58220" stroke-width="5" stroke-linecap="round"/>
                </svg>
                <div>
                    <p class="text-2xl font-bold leading-none tracking-wide">PDTS</p>
                    <p class="mt-1 text-xs leading-snug text-white/80">Physical Document<br>Tracking System</p>
                </div>
            </div>

        </div>

        <div class="max-w-xl lg:-ml-[12%] lg:w-full lg:max-w-none">

            <h1 class="text-4xl font-bold leading-tight tracking-tight lg:text-5xl 2xl:text-6xl">
                Physical Document<br>
                <span>Tracking </span><span class="text-[#F58220]">System</span>
            </h1>

            <div class="mt-4 h-1 w-20 rounded-full bg-[#F58220]"></div>

            <p class="mt-4 max-w-lg text-base leading-7 text-white/85 lg:max-w-none lg:text-xl">
                Track, manage and monitor the movement of physical documents across ZESCO.
            </p>

        </div>

        <div class="grid gap-3 sm:grid-cols-3 lg:-ml-[12%] lg:w-full">

            <div class="flex flex-col items-center gap-2 rounded-xl bg-[#F58220] px-4 py-4 text-center text-white shadow-lg shadow-black/15">
                <svg class="h-8 w-8 text-white" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                </svg>
                <span class="text-sm font-semibold lg:text-base">Register Documents</span>
            </div>

            <div class="flex flex-col items-center gap-2 rounded-xl bg-[#1E7FD6] px-4 py-4 text-center text-white shadow-lg shadow-black/15">
                <svg class="h-8 w-8 text-white" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" />
                </svg>
                <span class="text-sm font-semibold lg:text-base">Forward &amp; Acknowledge</span>
            </div>

            <div class="flex flex-col items-center gap-2 rounded-xl bg-[#075B35] px-4 py-4 text-center text-white shadow-lg shadow-black/15">
                <svg class="h-8 w-8 text-white" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
                </svg>
                <span class="text-sm font-semibold lg:text-base">Track to Completion</span>
            </div>

        </div>

    </section>

    {{-- RIGHT: FLOATING LOGIN CARD --}}
    <div class="relative z-10 mx-auto w-full max-w-md px-6 pb-16 pt-6 lg:absolute lg:right-[6%] lg:top-1/2 lg:mx-0 lg:h-[83%] lg:w-[32%] lg:max-w-none lg:-translate-y-1/2 lg:p-0">

        <div class="relative flex h-auto flex-col justify-center overflow-hidden rounded-3xl bg-white px-8 py-10 shadow-[0_30px_70px_rgba(0,0,0,0.25)] sm:px-10 lg:h-full">

            <div class="flex flex-col items-center text-center">

                <img
                    src="{{ asset('assets-dashboard/img/logo.png') }}"
                    alt="ZESCO"
                    class="h-28 w-auto object-contain">

                <p class="mt-4 text-3xl font-extrabold tracking-wide text-[#075B35]">PDTS</p>
                <p class="mt-1 text-sm text-[#667085]">Physical Document Tracking System</p>

                <div class="mt-6 h-px w-full bg-slate-200" aria-hidden="true"></div>

                <h2 class="mt-6 text-xl font-semibold text-slate-900">Sign In to Your Account</h2>

            </div>

            <div class="relative z-10 mt-6">
                {{ $slot }}
            </div>

            <img
                src="{{ asset('assets-dashboard/dist/img/login/document-stack.png') }}"
                alt=""
                aria-hidden="true"
                class="pointer-events-none absolute bottom-2 right-2 z-0 w-28 opacity-20 [filter:hue-rotate(-70deg)]">

        </div>

    </div>

</main>

@fluxScripts

</body>

</html>
