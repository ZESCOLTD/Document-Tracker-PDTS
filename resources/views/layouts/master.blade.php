<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config("app.name") }}</title>

    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="{{asset('assets-dashboard/plugins/fontawesome-free/css/all.min.css')}}">
    <link rel="stylesheet" href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css">
    <link rel="stylesheet" href="{{asset('assets-dashboard/plugins/tempusdominus-bootstrap-4/css/tempusdominus-bootstrap-4.min.css')}}">
    <link rel="stylesheet" href="{{asset('assets-dashboard/plugins/icheck-bootstrap/icheck-bootstrap.min.css')}}">
    <link rel="stylesheet" href="{{asset('assets-dashboard/dist/css/adminlte.min.css')}}">
    <link rel="stylesheet" href="{{asset('assets-dashboard/plugins/overlayScrollbars/css/OverlayScrollbars.min.css')}}">


{{--    <link href="{{ asset('assets-dashboard/css/select2/select2.css')}}" rel="stylesheet"/>--}}
{{--    <link href="{{ asset('assets-dashboard/css/select2/select2-bootstrap4.min.css')}}" rel="stylesheet"/>--}}
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.bootstrap4.min.css" rel="stylesheet">


{{--    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"/>--}}
    <style>
        /*body {*/
        /*    font-family: 'Inter', sans-serif;*/
        /*    -webkit-font-smoothing: antialiased;*/
        /*    -moz-osx-font-smoothing: grayscale;*/
        /*}*/
        /*@font-face {*/
        /*    font-family: 'DM Sans';*/
        /*    src: url('/assets-dashboard/fonts/DMSans_18pt-Black.ttf') format('truetype');*/
        /*    font-weight: 900; !* Black *!*/
        /*    font-style: normal;*/
        /*}*/
        @font-face {
            font-family: 'DM Sans';
            src: url('/assets-dashboard/fonts/DMSans-Light.ttf') format('truetype');
            font-weight: 900; /* Black */
            font-style: normal;
        }

        body {
            font-family: 'DM Sans', sans-serif !important;
        }



        /* Keeping your custom chart and watermark styles */
        .chart-outer { margin: 2em auto; }
        .graph-container, #container { min-height: 600px; margin-top: 2em; min-width: 380px; }
        .watermark-image {
            background-image: url({{ asset('assets-dashboard/dist/Screenshot_6.png')}});
            height: 100%;
            background-position: center;
            background-repeat: no-repeat;
            background-size: cover;
        }
        /* Make Select2 match Bootstrap form-control height */
        .select2-container .select2-selection--single {
            height: 38px !important; /* Bootstrap default input height */
            padding: 6px 12px;
            border: 1px solid #dee2e6;
        }

        /* Align text vertically */
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 24px;
        }

        /* Align dropdown arrow */
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 38px;
        }

        /* Match border radius */
        .select2-container--default .select2-selection--single {
            border-radius: 0.375rem;
        }

         .text-uppercase-input {
             text-transform: uppercase;
         }
        .nav-icon {
            color: {{config('theme.color_accent')}} !important;
        }

        .nav-link.active {
            background-color: {{config('theme.color_accent')}} !important;
            color: #fff !important;
        }

        /* Fix icon visibility when active */
        .nav-link.active .nav-icon {
            color: #fff !important;
        }

        .main-sidebar .nav-link p,
        .main-sidebar .nav-header {
            color: #fff !important;
        }

        /* Table headers everywhere use the sidebar colour. Overrides .thead-light, .table-light and inline backgrounds. */
        .table > thead > tr > th,
        .table > thead > tr > td {
            background-color: {{ config('theme.color_sidebar') }} !important;
            color: {{ config('theme.color_primary_text_light') }} !important;
            border-color: {{ config('theme.color_sidebar') }} !important;
            font-weight: 600;
            vertical-align: middle;
        }
        .table > thead > tr > th a,
        .table > thead > tr > th .text-muted,
        .table > thead > tr > th small {
            color: {{ config('theme.color_primary_text_light') }} !important;
        }

        /* Row "Actions" dropdowns: let the menu spill out of the table on tablets and up. */
        @media (min-width: 768px) {
            .table-responsive.actions-overflow { overflow: visible; }
        }

        .nav-link p {
            color: #fff !important;
        }

        .nav-header {
            color: {{config('theme.color_accent')}} !important;
        }


         .user-status-dot {
             position: absolute;
             bottom: 0;
             right: 0;
             width: 10px;
             height: 10px;
             border-radius: 50%;
             border: 2px solid #fff;
         }

        /* 🟢 ONLINE (pulse) */
        .user-status-dot.online {
            background: #28a745;
            box-shadow: 0 0 0 rgba(40,167,69, 0.7);
            animation: pulse 1.5s infinite;
        }

        /* 🟡 IDLE */
        .user-status-dot.idle {
            background: #ffc107;
        }
        .bg-primary{
            background-color: {{config('theme.color_sidebar')}} !important;
        }
        .bg-warning{
            background-color: {{config('theme.color_accent')}} !important;
        }
        .text-warning{
            color: {{config('theme.color_accent')}} !important;
        }
        .text-primary{
            color: {{config('theme.color_sidebar')}} !important;
        }
        .btn-primary{
            background: {{config('theme.color_accent')}} !important;
            outline: {{config('theme.color_accent')}} !important;
        }
        .btn-outline-secondary:hover,
        .btn-outline-secondary:active,
        .btn-outline-secondary.active,
        .show > .btn-outline-secondary.dropdown-toggle {
            background-color: {{ config('theme.color_sidebar') }} !important;
            border-color: {{ config('theme.color_sidebar') }} !important;
            color: #fff !important;
            box-shadow: none !important;
        }
        .btn-outline-secondary:focus {
            box-shadow: none !important;
            outline: none;
        }
        .btn-outline-secondary:focus-visible {
            outline: 2px solid {{ config('theme.color_sidebar') }};
            outline-offset: 2px;
        }
        .text-white{
             color: white !important;
         }
        /* ⚫ OFFLINE */
        .user-status-dot.offline {
            background: #6c757d;
        }

        /* Pulse animation */
        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(40,167,69, 0.7);
            }
            70% {
                box-shadow: 0 0 0 6px rgba(40,167,69, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(40,167,69, 0);
            }
        }
        /* Keep Bootstrap modal above its backdrop (prevents backdrop blocking clicks) */
        .modal-backdrop {
            z-index: 1040 !important;
        }
        .modal {
            z-index: 1050 !important;
        }

        .contract-requests-table {
            overflow-x: auto !important;
            overflow-y: visible !important;
        }

        .contract-requests-table .dropdown-menu {
            z-index: 9999 !important;
        }
        .table-responsive {
            overflow-x: auto;
            overflow-y: visible !important;
        }

        .table-responsive .dropdown-menu {
            z-index: 9999 !important;
        }
    </style>

    <link href="{{ asset('assets-dashboard/css/select2/select2.min.css')}}" rel="stylesheet"/>
    <link href="{{ asset('assets-dashboard/css/select2/select2-bootstrap4.min.css')}}" rel="stylesheet"/>

    @stack('custom-styles')
    @livewireStyles
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

    <div class="preloader flex-column justify-content-center align-items-center">
        <img class="animation__shake" src="{{asset('assets-dashboard/dist/img/logo/zesco_logo.png')}}" alt="Logo" height="60" width="60">
    </div>

    @include('layouts.navbar')
    @include('layouts.sidebar')

    <div class="content-wrapper">
        {{-- Only when the page gives a header; an empty one left a gap above every page. --}}
        @if (filled($pageHeader ?? null))
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0 text-dark">{{ $pageHeader }}</h1>
                    </div>
                </div>
            </div>
        </div>
        @endif

        <section class="content">
            <div class="container-fluid">
                {{ $slot }} </div>
        </section>
    </div>

    @include('layouts.footer')

    <aside class="control-sidebar control-sidebar-dark"></aside>
</div>
<div id="supportChatBox"
     style="display: none;
       position: fixed;
       bottom: 100px;
       right: 25px;
       width: 360px;
       height: 500px;
       z-index: 10000;
       background: #fff;
       border-radius: 10px;
       box-shadow: 0 10px 30px rgba(0,0,0,0.2);
       overflow: hidden;
       flex-direction: column;">

    <!-- HEADER -->
    <div style="background: {{ config('theme.color_sidebar') }};
                color: white;
                padding: 10px;
                font-weight: bold;
                display:flex;
                justify-content: space-between;">

        <span>Support Chat</span>

        <button onclick="toggleSupportChat()"
                style="background: transparent; border: none; color: white;">
            ✕
        </button>
    </div>

    <!-- CHAT BODY -->
    <div id="chatBody"
         style="flex:1;
                padding:10px;
                overflow-y:auto;
                background:#f8f9fa;">

        <div class="text-muted small text-center mt-3">
            Describe your issue or attach files 👇
        </div>

        <!-- messages will go here -->
        <div id="messages"></div>
    </div>

    <!-- INPUT AREA -->
    <div style="padding:10px; border-top:1px solid #ddd;">

        <!-- TEXT EDITOR -->
        <div contenteditable="true"
             id="chatInput"
             placeholder="Type your issue..."
             style="min-height:60px;
                    border:1px solid #ccc;
                    padding:8px;
                    border-radius:6px;
                    background:white;
                    outline:none;">
        </div>

        <!-- FILE DROP -->
        <div id="dropZone"
             style="margin-top:8px;
                    padding:10px;
                    border:2px dashed #ccc;
                    text-align:center;
                    font-size:12px;
                    color:#888;">
            Drag & Drop files here or click to upload
            <input type="file" multiple style="display:none;" id="fileInput">
        </div>

        <button class="btn btn-primary btn-sm mt-2 w-100"
                onclick="sendSupportMessage()">
            Send Message
        </button>

    </div>
</div>
<script src="{{asset('assets-dashboard/plugins/jquery/jquery.min.js')}}"></script>
<script src="{{asset('assets-dashboard/plugins/jquery-ui/jquery-ui.min.js')}}"></script>
<script>$.widget.bridge('uibutton', $.ui.button)</script>
<script src="{{asset('assets-dashboard/plugins/bootstrap/js/bootstrap.bundle.min.js')}}"></script>
<script src="{{asset('assets-dashboard/dist/js/adminlte.js')}}"></script>
<script src="{{ asset('assets-dashboard/js/select2/select2.full.min.js')}}" type="text/javascript"></script>

<script src="{{asset('assets-dashboard/js/highcharts/highcharts.js')}}"></script>
<script src="{{asset('assets-dashboard/js/highcharts/drilldown.js')}}"></script>

{{--<script src="{{asset('assets-dashboard/dist/js/adminlte.js')}}"></script>--}}



<script src="{{asset('assets-dashboard/js/tom-select.complete.min.js')}}"></script>

@livewireScripts
{{-- Flux, used by the Profile and Appearance settings pages (from the starter kit). --}}
@fluxScripts

{{--
    PDTS's own confirmation box, used by every wire:confirm instead of the
    browser's plain "localhost says" pop-up. The message is the wire:confirm
    text; the confirming button says what it does ("Yes, remove").
--}}
<div id="pdts-confirm" class="pdts-confirm" role="dialog" aria-modal="true" aria-labelledby="pdts-confirm-title" aria-describedby="pdts-confirm-message" hidden>
    <div class="pdts-confirm-backdrop" data-confirm-dismiss></div>
    <div class="pdts-confirm-box">
        <div class="pdts-confirm-icon" aria-hidden="true"><i class="fas fa-question"></i></div>
        <h2 id="pdts-confirm-title" class="pdts-confirm-title">Please confirm</h2>
        <p id="pdts-confirm-message" class="pdts-confirm-message"></p>
        <div class="pdts-confirm-actions">
            <button type="button" class="btn btn-light border" data-confirm-dismiss>No, go back</button>
            <button type="button" class="btn" data-confirm-accept>Yes</button>
        </div>
    </div>
</div>
<style>
    .pdts-confirm { position: fixed; inset: 0; z-index: 2000; display: flex; align-items: center; justify-content: center; padding: 1rem; }
    .pdts-confirm[hidden] { display: none; }
    .pdts-confirm-backdrop { position: absolute; inset: 0; background: rgba(9, 30, 32, .45); animation: pdtsFade .15s ease-out; }
    .pdts-confirm-box { position: relative; width: 100%; max-width: 27rem; background: #fff; border-radius: .875rem; box-shadow: 0 18px 50px rgba(9, 63, 66, .28); padding: 1.5rem 1.5rem 1.25rem; text-align: center; animation: pdtsRise .18s ease-out; }
    .pdts-confirm-icon { width: 3rem; height: 3rem; margin: 0 auto .75rem; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; background: #e2eeee; color: {{ config('theme.color_sidebar', '#093f42') }}; }
    .pdts-confirm.is-danger .pdts-confirm-icon { background: #fde8e7; color: #b4332d; }
    .pdts-confirm-title { font-size: 1.125rem; font-weight: 700; margin: 0 0 .5rem; color: #1f2d2d; }
    .pdts-confirm-message { font-size: .9375rem; color: #4a5a5a; margin: 0 0 1.25rem; white-space: pre-line; }
    .pdts-confirm-actions { display: flex; gap: .5rem; justify-content: center; flex-wrap: wrap; }
    .pdts-confirm-actions .btn { min-width: 8.5rem; font-weight: 600; }
    .pdts-confirm [data-confirm-accept] { background: {{ config('theme.color_sidebar', '#093f42') }}; border-color: {{ config('theme.color_sidebar', '#093f42') }}; color: #fff; }
    .pdts-confirm.is-danger [data-confirm-accept] { background: #c0392b; border-color: #c0392b; }
    @keyframes pdtsFade { from { opacity: 0; } to { opacity: 1; } }
    @keyframes pdtsRise { from { opacity: 0; transform: translateY(8px) scale(.98); } to { opacity: 1; transform: none; } }
</style>
<script>
    (() => {
        // Once per browser tab: pages opened in place (wire:navigate) run this again.
        if (window.pdtsConfirm) return;

        // First words that mean something is taken away or ended: shown in red.
        const dangerous = /^(remove|delete|discard|cancel|suspend|end|reset|reject|give up|revoke)\b/i;
        // What the confirming button says: "Yes, remove", "Yes, send"...; otherwise "Yes, continue".
        const verbs = /^(remove|delete|discard|cancel|suspend|end|reset|reject|revoke|send|approve|reinstate|resend|issue|sign|complete|stop|give up)\b/i;
        let pending = null;
        let lastFocus = null;
        // The box is looked up each time: a page opened in place brings a new one.
        const box = () => document.getElementById('pdts-confirm');

        const close = () => {
            const dialog = box();
            if (dialog) dialog.hidden = true;
            pending = null;
            document.removeEventListener('keydown', onKey, true);
            if (lastFocus && document.contains(lastFocus)) lastFocus.focus();
        };
        const onKey = (e) => {
            const dialog = box();
            if (!dialog) return;
            if (e.key === 'Escape') { e.preventDefault(); close(); }
            if (e.key === 'Tab') { // keep the focus inside the box
                const buttons = [...dialog.querySelectorAll('button')];
                const i = buttons.indexOf(document.activeElement);
                e.preventDefault();
                buttons[(i + (e.shiftKey ? -1 : 1) + buttons.length) % buttons.length].focus();
            }
        };

        // PDTS's question: the box, and onYes when "Yes" is pressed. Without the box
        // on the page (it should always be there), the browser's own question asks instead.
        window.pdtsConfirm = (text, onYes) => {
            const dialog = box();
            if (!dialog) {
                if (window.confirm(text)) onYes();
                return;
            }
            const accept = dialog.querySelector('[data-confirm-accept]');
            const verb = (text.match(verbs) || [])[0];
            const danger = dangerous.test(text) || /^completion is final/i.test(text);
            dialog.classList.toggle('is-danger', danger);
            dialog.querySelector('.pdts-confirm-icon i').className = danger ? 'fas fa-exclamation' : 'fas fa-question';
            dialog.querySelector('#pdts-confirm-title').textContent = danger ? 'Are you sure?' : 'Please confirm';
            dialog.querySelector('#pdts-confirm-message').textContent = text;
            accept.textContent = verb ? 'Yes, ' + verb.toLowerCase() : (/^completion/i.test(text) ? 'Yes, complete' : 'Yes, continue');
            pending = onYes;
            lastFocus = document.activeElement;
            dialog.hidden = false;
            document.addEventListener('keydown', onKey, true);
            (danger ? dialog.querySelector('[data-confirm-dismiss].btn') : accept).focus();
        };

        window.addEventListener('click', (e) => {
            // The box's own buttons (looked up by what they are, so a new box works too).
            if (e.target.closest('#pdts-confirm [data-confirm-accept]')) {
                const go = pending;
                close();
                if (go) go();

                return;
            }
            if (e.target.closest('#pdts-confirm [data-confirm-dismiss]')) {
                close();

                return;
            }

            // Every element with wire:confirm: the click is held, the box asks, and on "Yes"
            // the same click is made again with the browser's own question switched off.
            // On the window, so nothing on a page sees the click before the box does.
            const el = e.target.closest('[wire\\:confirm]');
            if (!el || el.__pdtsConfirmed) return;
            e.preventDefault();
            e.stopImmediatePropagation();
            window.pdtsConfirm((el.getAttribute('wire:confirm') || 'Are you sure?').replaceAll('\\n', '\n'), () => {
                el.__livewire_confirm = (action) => action();
                el.__pdtsConfirmed = true;
                el.click();
                el.__pdtsConfirmed = false;
            });
        }, true);
    })();
</script>

@stack('custom-scripts')
<script>

</script>
<script type="text/javascript">
    $(function () { $('[data-toggle="popover"]').popover() });

    document.addEventListener('livewire:init', () => {
        Livewire.on('closeModal', (event) => {
            $('.modal').modal('hide');
            $(document).Toasts('create', {
                icon: 'fas fa-envelope fa-lg',
                class: 'bg-success',
                title: event.title,
                body: event.body,
            });
        });
    });
</script>
<script>
    function toggleSupportChat() {
        const box = document.getElementById('supportChatBox');
        box.style.display = (box.style.display === 'none' || box.style.display === '') ? 'flex' : 'none';
    }

    function sendSupportMessage() {
        let message = document.getElementById('chatInput').innerText;

        if (!message.trim()) return;

        let msgBox = document.getElementById('messages');

        let div = document.createElement('div');
        div.classList.add('alert', 'alert-primary', 'p-2', 'mb-2');
        div.innerText = message;

        msgBox.appendChild(div);

        document.getElementById('chatInput').innerText = '';
    }

    // Drag & Drop
    const dropZone = document.getElementById('dropZone');

    dropZone.addEventListener('click', () => {
        document.getElementById('fileInput').click();
    });

    dropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropZone.style.borderColor = '#007bff';
    });

    dropZone.addEventListener('dragleave', () => {
        dropZone.style.borderColor = '#ccc';
    });

    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.style.borderColor = '#ccc';

        let files = e.dataTransfer.files;
        console.log(files); // later send to Livewire
    });
</script>
{{--@stack('custom-scripts')--}}
{{--@livewireScripts--}}
@stack('scripts')
<!-- FLOATING SUPPORT BUTTON -->
<div style="position:fixed; bottom:25px; right:25px; z-index:9999;">

    <button class="btn btn-primary rounded-circle shadow"
            style="width:60px; height:60px;"
            onclick="document.getElementById('supportModal').classList.toggle('d-none')">

        <i class="fas fa-comments"></i>
    </button>

</div>

<div id="supportModal"
     class="d-none"
     style="position:fixed; bottom:100px; right:25px; width:380px; background:white; border-radius:10px; box-shadow:0 10px 30px rgba(0,0,0,0.2); z-index:10000;">

    <div class="p-2 bg-primary text-white d-flex justify-content-between">
        Support
        <span onclick="document.getElementById('supportModal').classList.add('d-none')"
              style="cursor:pointer;">✕</span>
    </div>

    <div class="p-2">
        @livewire('support.chat-box')
    </div>

</div>
</body>
</html>
