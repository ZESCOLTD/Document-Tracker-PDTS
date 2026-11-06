<div class="container-fluid">

    {{-- HEADER --}}
    <div class="d-flex justify-content-between align-items-center mb-4">

        <div class="d-flex align-items-center">

            {{-- AVATAR --}}
            <div class="position-relative mr-3">

                @if($user->avatar)
                    <img src="{{ asset('storage/'.$user->avatar) }}"
                         class="rounded-circle shadow"
                         style="width:70px; height:70px; object-fit:cover;">
                @else
                    <div class="rounded-circle d-flex align-items-center justify-content-center shadow"
                         style="
                            width:70px;
                            height:70px;
                            background: {{ config('theme.color_accent') }};
                            color:#fff;
                            font-size:20px;
                            font-weight:600;">
                        {{ strtoupper(substr($user->name, 0, 2)) }}
                    </div>
                @endif

                {{-- STATUS --}}
                @php
                    $lastSeen = $user->lastSeen();
                    $isOnline = $lastSeen && $lastSeen->gt(now()->subMinutes(5));
                    $isIdle = $lastSeen && $lastSeen->between(now()->subMinutes(10), now()->subMinutes(5));
                @endphp

                <span class="
                    user-status-dot
                    {{ $isOnline ? 'online' : ($isIdle ? 'idle' : 'offline') }}
                "></span>

            </div>

            <div>
                <h4 class="mb-0" style="color: {{ config('theme.color_primary') }}">
                    {{ $user->name }}
                </h4>
                <small class="text-muted">
                    {{ $user->email }}
                </small>
            </div>

        </div>

        <div>
            <a href="{{ route('users.edit', $user->id) }}"
               class="btn btn-warning mr-2">
                <i class="fas fa-edit"></i> Edit
            </a>

            <a href="{{ route('users.index') }}"
               class="btn btn-light">
                Back
            </a>
        </div>
    </div>

    {{-- QUICK STATS --}}
    <div class="row mb-4">

        <div class="col-md-3 mb-3">
            <div class="card elevation-2">
                <div class="card-body">
                    <small class="text-muted">Status</small>
                    <h5 class="mb-0">
                        {{ !$user->is_banned ? 'Active' : 'Banned' }}
                    </h5>
                </div>
            </div>
        </div>

        <div class="col-md-3 mb-3">
            <div class="card elevation-2">
                <div class="card-body">
                    <small class="text-muted">Role</small>
                    <h5 class="mb-0">
                        {{ $user->role->name ?? '—' }}
                    </h5>
                </div>
            </div>
        </div>

        <div class="col-md-3 mb-3">
            <div class="card elevation-2">
                <div class="card-body">
                    <small class="text-muted">Joined</small>
                    <h5 class="mb-0">
                        {{ $user->created_at->format('d M Y') }}
                    </h5>
                </div>
            </div>
        </div>

        <div class="col-md-3 mb-3">
            <div class="card elevation-2">
                <div class="card-body">
                    <small class="text-muted">Last Seen</small>
                    <h5 class="mb-0">
                        {{ $user->lastSeen() ? $user->lastSeen()->diffForHumans() : 'Never' }}
                    </h5>
                </div>
            </div>
        </div>

    </div>

    {{-- MAIN CARD --}}
    <div class="card elevation-2">

        {{-- TABS --}}
        <div class="card-header p-0 border-0">
            <ul class="nav nav-tabs"
                style="background: {{ config('theme.color_primary') }};">

                <li class="nav-item">
                    <a class="nav-link active text-white"
                       data-toggle="tab"
                       href="#overview">
                        <i class="fas fa-user mr-1"></i> Overview
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link text-white"
                       data-toggle="tab"
                       href="#work">
                        <i class="fas fa-briefcase mr-1"></i> Work Info
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link text-white"
                       data-toggle="tab"
                       href="#activity">
                        <i class="fas fa-clock mr-1"></i> Activity
                    </a>
                </li>

            </ul>
        </div>

        {{-- TAB CONTENT --}}
        <div class="card-body">

            <div class="tab-content">

                {{-- OVERVIEW --}}
                <div class="tab-pane fade show active" id="overview">

                    <div class="row">

                        <div class="col-md-6 mb-3">
                            <label class="text-muted">Full Name</label>
                            <div class="fw-bold">{{ $user->name }}</div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="text-muted">Email</label>
                            <div class="fw-bold">{{ $user->email }}</div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="text-muted">Man No</label>
                            <div>{{ $user->man_no ?? '—' }}</div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="text-muted">Phone</label>
                            <div>{{ $user->mobile_no ?? '—' }}</div>
                        </div>

                    </div>

                </div>

                {{-- WORK --}}
                <div class="tab-pane fade" id="work">

                    <div class="row">

                        <div class="col-md-6 mb-3">
                            <label class="text-muted">Directorate</label>
                            <div>{{ $user->directorate ?? '—' }}</div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="text-muted">Job Title</label>
                            <div>{{ $user->job_title ?? '—' }}</div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="text-muted">Department</label>
                            <div>{{ $user->department ?? '—' }}</div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="text-muted">Station</label>
                            <div>{{ $user->station ?? '—' }}</div>
                        </div>

                    </div>

                </div>

                {{-- ACTIVITY --}}
                <div class="tab-pane fade" id="activity">

                    <div class="mb-3">
                        <label class="text-muted">Last Seen</label>
                        <div class="fw-bold">
                            {{ $user->lastSeen() ? $user->lastSeen()->format('d M Y H:i') : 'Never' }}
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="text-muted">Account Created</label>
                        <div>{{ $user->created_at->format('d M Y H:i') }}</div>
                    </div>

                    <div class="mb-3">
                        <label class="text-muted">Status</label>
                        <div>
                            @if(!$user->is_banned)
                                <span class="badge bg-success">Active</span>
                            @else
                                <span class="badge bg-danger">Banned</span>
                            @endif
                        </div>
                    </div>

                </div>

            </div>

        </div>
    </div>
</div>
