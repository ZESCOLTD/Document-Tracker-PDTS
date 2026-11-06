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
            background-color: {{config('theme.color_primary')}} !important;
        }
        .bg-warning{
            background-color: {{config('theme.color_accent')}} !important;
        }
        .text-warning{
            color: {{config('theme.color_accent')}} !important;
        }
        .text-primary{
            color: {{config('theme.color_primary')}} !important;
        }
        .btn-primary{
            background: {{config('theme.color_accent')}} !important;
            outline: {{config('theme.color_accent')}} !important;
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
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0 text-dark">{{ $pageHeader ?? '' }}</h1>
                    </div>
                </div>
            </div>
        </div>

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
    <div style="background: {{ config('theme.color_primary') }};
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
