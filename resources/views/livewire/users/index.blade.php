<div>

    <x-slot name="title">User Management</x-slot>
{{--    <x-slot name="pageHeader">User Registry</x-slot>--}}

    <div class="container-fluidr">

        {{-- HEADER ACTIONS --}}

            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">

                <div>
                    <h3 class="font-weight-bold mb-1">
                        User Management
                    </h3>
                    <p class="text-muted mb-0">
                       Manage User Access
                    </p>
                </div>

{{--                <div class="d-flex gap-2 flex-wrap">--}}

                    {{-- GLOBAL SYNC --}}
                    <button class="btn btn-info mr-2"
                            wire:click="startFullSync"
                            wire:loading.attr="disabled">

        <span wire:loading.remove wire:target="startFullSync">
            <i class="fas fa-sync-alt mr-1"></i>
            Sync PHRIS Users
        </span>

                        <span wire:loading wire:target="startFullSync">
            <span class="spinner-border spinner-border-sm"></span>
            Starting...
        </span>

                    </button>

                    {{-- NEW USER --}}
                    <button class="btn text-white bg-warning"
                            data-toggle="modal"
                            data-target="#userModal">

                        <i class="fas fa-plus mr-1"></i> New User

                    </button>

                </div>



        {{-- KPI CARDS (LIGHT REGISTRY STYLE) --}}
        <div class="row mb-4">

            <div class="col-md-3 mb-3">
                <div class="card elevation-2 border-0">
                    <div class="card-body d-flex justify-content-between align-items-center">

                        <div>
                            <small class="text-muted text-uppercase">Total Users</small>
                            <h3 class="mb-0 font-weight-bold">{{ $totalUsers }}</h3>
                            <small class="text-success">+{{ $newUsersThisMonth }} this month</small>
                        </div>

                        <i class="fas fa-users text-primary" style="font-size:2rem;"></i>

                    </div>
                </div>
            </div>

            <div class="col-md-3 mb-3">
                <div class="card elevation-2 border-0">
                    <div class="card-body d-flex justify-content-between align-items-center">

                        <div>
                            <small class="text-muted text-uppercase">Active Users</small>
                            <h3 class="mb-0 text-success font-weight-bold">{{ $activeUsers }}</h3>
                            <small class="text-muted">{{ $activePercentage }}%</small>
                        </div>

                        <i class="fas fa-user-check text-success" style="font-size:2rem;"></i>

                    </div>
                </div>
            </div>

            <div class="col-md-3 mb-3">
                <div class="card elevation-2 border-0">
                    <div class="card-body d-flex justify-content-between align-items-center">

                        <div>
                            <small class="text-muted text-uppercase">Admins</small>
                            <h3 class="mb-0 font-weight-bold" style="color:{{config('theme.color_accent')}};">
                                {{ $adminUsers }}
                            </h3>
                            <small class="text-muted">Privileged</small>
                        </div>

                        <i class="fas fa-user-shield text-warning" style="font-size:2rem;"></i>

                    </div>
                </div>
            </div>

            <div class="col-md-3 mb-3">
                <div class="card elevation-2 border-0">
                    <div class="card-body d-flex justify-content-between align-items-center">

                        <div>
                            <small class="text-muted text-uppercase">Banned</small>
                            <h3 class="mb-0 text-danger font-weight-bold">{{ $bannedUsers }}</h3>
                            <small class="text-muted">Restricted</small>
                        </div>

                        <i class="fas fa-user-lock text-danger" style="font-size:2rem;"></i>

                    </div>
                </div>
            </div>

        </div>
        @if($syncRunning)

            <div wire:poll.2s="refreshSyncProgress"
                 class="card border-0 elevation-1 mb-3">

                <div class="card-body">

                    <div class="d-flex justify-content-between mb-2">

                        <strong>
                            <i class="fas fa-sync-alt fa-spin text-info"></i>
                            Synchronizing Users From PHRIS
                        </strong>

                        <span>
                    {{ $syncedUsers }} / {{ $totalSyncUsers }}
                </span>

                    </div>

                    <div class="progress" style="height: 20px;">

                        <div class="progress-bar progress-bar-striped progress-bar-animated bg-info"
                             role="progressbar"
                             style="width: {{ $syncPercentage }}%">

                            {{ $syncPercentage }}%

                        </div>

                    </div>

                </div>

            </div>

        @endif
        {{-- TABLE CARD (FMS STYLE) --}}
        <div class="card elevation-2">

            {{-- LIGHT HEADER (NO DARK BAR) --}}
            <div class="card-header bg-white border-0">

                <div class="d-flex justify-content-between align-items-center flex-wrap">

                    <h5 class="mb-0">
                        <i class="fas fa-list mr-2 text-primary"></i> Users Register
                    </h5>

                    <div class="d-flex gap-2 flex-wrap">

                        <input type="text"
                               class="form-control form-control-sm"
                               placeholder="Search users..."
                               style="width:220px;"
                               wire:model.live.debounce.300ms="search">

                        <select class="form-control form-control-sm"
                                style="width:150px;"
                                wire:model.live="statusFilter">
                            <option value="">All Status</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>

                    </div>

                </div>

            </div>

            <div class="table-responsive position-relative">

                {{-- LOADER --}}
                <div wire:loading wire:target="search"
                     style="position:absolute; top:10px; right:15px;">
                    <span class="spinner-border spinner-border-sm text-primary"></span>
                </div>

                <table class="table table-hover mb-0">

                    <thead style="background:#f4f6f9;">
                    <tr>
                        <th>#</th>
                        <th>User</th>
                        <th>Contact</th>
                        <th>Job</th>
                        <th>Directorate</th>
                        <th>Status</th>
{{--                        <th>Activity</th>--}}
                        <th class="text-center">Actions</th>
                    </tr>
                    </thead>

                    <tbody>

                    @forelse($users as $index => $user)

                        @php
                            $lastSeen = $user->last_activity
                                ? \Carbon\Carbon::createFromTimestamp($user->last_activity)
                                : null;
                        @endphp

                        <tr>

                            <td>{{ $users->firstItem() + $index }}</td>

                            {{-- USER --}}
                            <td>
                                <div class="d-flex align-items-center">

                                    @if($user->avatar)
                                        <img src="{{ asset('storage/'.$user->avatar) }}"
                                             class="rounded-circle mr-2"
                                             style="width:40px;height:40px;">
                                    @else
                                        <div class="rounded-circle d-flex align-items-center justify-content-center mr-2"
                                             style="width:40px;height:40px;background:#e9ecef;">
                                            {{ strtoupper(substr($user->name,0,2)) }}
                                        </div>
                                    @endif

                                    <div>
                                        <div class="font-weight-bold">{{ $user->name }}</div>
                                        <small class="text-muted">{{ $user->man_no ?? '—' }}</small>
                                    </div>

                                </div>
                            </td>

                            <td>
                                <div>{{ $user->email }}</div>
                                <small class="text-muted">{{ $user->mobile_no }}</small>
                            </td>

                            <td>{{ $user->job_title }}</td>

                            <td>{{ $user->directorate }}</td>

                            <td>
                                <span class="badge px-3 py-2
                                    {{ $user->is_banned ? 'badge-danger' : 'badge-success' }}">
                                    {{ $user->is_banned ? 'Banned' : 'Active' }}
                                </span>
                            </td>

{{--                            <td>--}}
{{--                                @if($lastSeen)--}}
{{--                                    <small class="font-weight-bold">--}}
{{--                                        {{ $lastSeen->diffForHumans() }}--}}
{{--                                    </small>--}}
{{--                                @else--}}
{{--                                    <span class="text-muted">Never</span>--}}
{{--                                @endif--}}
{{--                            </td>--}}

                            <td class="text-center">

                                <div class="dropdown">

                                    <button class="btn btn-outline-warning dropdown-toggle"
                                            data-toggle="dropdown">
                                        Action
                                    </button>

                                    <div class="dropdown-menu dropdown-menu-right">

                                        <a class="dropdown-item" href="{{ route('users.show',$user->id) }}">
                                            View
                                        </a>

                                        <a class="dropdown-item" href="{{ route('users.edit',$user->id) }}">
                                            Edit
                                        </a>

                                        <button class="dropdown-item"
                                                wire:click="syncSingleUser({{ $user->id }})">

{{--                                            <i class="fas fa-sync-alt text-info mr-2"></i>--}}
                                            Sync From PHRIS

                                        </button>

                                        <div class="dropdown-divider"></div>

                                        <button class="dropdown-item text-danger"
                                                onclick="confirmDelete({{ $user->id }})">
                                            Delete
                                        </button>

                                    </div>

                                </div>

                            </td>

                        </tr>

                    @empty

                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                No users found
                            </td>
                        </tr>

                    @endforelse

                    </tbody>

                </table>

            </div>

            <div class="card-footer bg-white">
                {{ $users->links() }}
            </div>

        </div>

    </div>
    <!-- USER CREATE MODAL -->
    <div wire:ignore.self class="modal fade" id="userModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content border-0">

                {{-- HEADER --}}
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user-plus text-primary mr-2"></i>
                        Add New User
                    </h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>

                {{-- BODY --}}
                <div class="modal-body">

                    {{-- ALERTS --}}
                    @if (session()->has('error'))
                        <div class="alert alert-danger">
                            {{ session('error') }}
                        </div>
                    @endif

                    @if (session()->has('success'))
                        <div class="alert alert-success">
                            {{ session('success') }}
                        </div>
                    @endif

                    <div class="row">

                        {{-- MAN NUMBER --}}
                        <div class="col-md-6 mb-3">
                            <label>Man Number</label>
                            <div class="input-group">
                                <input type="text"
                                       class="form-control"
                                       wire:model.defer="modal_man_no"
                                       placeholder="Enter Man Number">

                                <div class="input-group-append">
                                    <button class="btn btn-primary"
                                            wire:click="fetchUserFromPhcms"
                                            wire:loading.attr="disabled">

                                    <span wire:loading.remove wire:target="fetchUserFromPhcms">
                                        Fetch
                                    </span>

                                        <span wire:loading wire:target="fetchUserFromPhcms">
                                        <span class="spinner-border spinner-border-sm"></span>
                                    </span>

                                    </button>
                                </div>
                            </div>
                        </div>

                        {{-- EMAIL --}}
                        <div class="col-md-6 mb-3">
                            <label>Email</label>
                            <input type="email"
                                   class="form-control"
                                   wire:model.defer="modal_email"
                                   readonly>
                        </div>

                        {{-- NAME --}}
                        <div class="col-md-6 mb-3">
                            <label>Full Name</label>
                            <input type="text"
                                   class="form-control"
                                   wire:model.defer="modal_name"
                                   readonly>
                        </div>

                        {{-- JOB TITLE --}}
                        <div class="col-md-6 mb-3">
                            <label>Job Title</label>
                            <input type="text"
                                   class="form-control"
                                   wire:model.defer="modal_job_title"
                                   readonly>
                        </div>

                        {{-- DIRECTORATE --}}
                        <div class="col-md-6 mb-3">
                            <label>Directorate</label>
                            <input type="text"
                                   class="form-control"
                                   wire:model.defer="modal_directorate"
                                   readonly>
                        </div>

                        {{-- AVATAR --}}
                        <div class="col-md-6 mb-3">
                            <label>Avatar</label>
                            <input type="file"
                                   class="form-control"
                                   wire:model="avatar">

                            {{-- PREVIEW --}}
                            @if ($avatar)
                                <div class="mt-2">
                                    <img src="{{ $avatar->temporaryUrl() }}"
                                         class="rounded"
                                         style="width:80px;height:80px;">
                                </div>
                            @endif
                        </div>

                    </div>

                </div>

                {{-- FOOTER --}}
                <div class="modal-footer">

                    <button type="button"
                            class="btn btn-light"
                            data-dismiss="modal">
                        Cancel
                    </button>

                    <button type="button"
                            class="btn text-white"
                            style="background: {{config('theme.color_accent')}}"
                            wire:click="saveFromModal"
                            wire:loading.attr="disabled">

                    <span wire:loading.remove wire:target="saveFromModal">
                        <i class="fas fa-save mr-1"></i> Save User
                    </span>

                        <span wire:loading wire:target="saveFromModal">
                        <span class="spinner-border spinner-border-sm"></span>
                    </span>

                    </button>

                </div>

            </div>
        </div>
    </div>
</div>
