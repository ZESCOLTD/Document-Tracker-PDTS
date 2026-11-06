<div class="container-fluid">

    {{-- HEADER --}}
    <div class="d-flex justify-content-between align-items-center mb-4">

        <div>
            <h4 class="mb-1" style="color: {{ config('theme.color_primary') }}">
                Edit User
            </h4>
            <small class="text-muted">{{ $user->name }}</small>
        </div>

        {{-- AVATAR --}}
        <div class="position-relative">

            @if($user->avatar)
                <img src="{{ asset('storage/'.$user->avatar) }}"
                     class="rounded-circle shadow"
                     style="width:60px; height:60px; object-fit:cover;">
            @else
                <div class="rounded-circle d-flex align-items-center justify-content-center shadow"
                     style="
                        width:60px;
                        height:60px;
                        background: {{ config('theme.color_accent') }};
                        color:#fff;
                        font-weight:600;
                        font-size:18px;">
                    {{ strtoupper(substr($user->name, 0, 2)) }}
                </div>
            @endif

        </div>
    </div>

    {{-- CARD --}}
    <div class="card elevation-2">
        {{-- ERRORS --}}
        @if ($errors->any())
            <div class="alert alert-danger m-3">
                <h6><i class="fas fa-exclamation-triangle mr-2"></i> Please fix the following:</h6>
                <ul class="mb-0 pl-3">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- SUCCESS --}}
        @if (session()->has('success'))
            <div class="alert alert-success alert-dismissible fade show m-3" role="alert">
                <i class="fas fa-check-circle mr-2"></i>
                {{ session('success') }}
                <button type="button" class="close" data-dismiss="alert">&times;</button>
            </div>
        @endif
        {{-- TABS --}}
        <div class="card-header p-0 border-0">
            <ul class="nav nav-tabs" id="userTabs" role="tablist"
                style="background: {{ config('theme.color_primary') }};">

                <li class="nav-item">
                    <a class="nav-link active text-white"
                       id="profile-tab"
                       data-toggle="tab"
                       href="#profile"
                       role="tab">
                        <i class="fas fa-user mr-1"></i> Profile
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link text-white"
                       id="work-tab"
                       data-toggle="tab"
                       href="#work"
                       role="tab">
                        <i class="fas fa-briefcase mr-1"></i> Work Info
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link text-white"
                       id="security-tab"
                       data-toggle="tab"
                       href="#security"
                       role="tab">
                        <i class="fas fa-lock mr-1"></i> Security
                    </a>
                </li>

            </ul>
        </div>

        {{-- BODY --}}
        <div class="card-body">

            <form wire:submit.prevent="update">

                <div class="tab-content">

                    {{-- PROFILE TAB --}}
                    <div class="tab-pane fade show active" id="profile">

                        {{-- Avatar Upload --}}
                        <div class="mb-3">
                            <label class="font-weight-bold">Profile Photo</label>
                            <input type="file" class="form-control" wire:model="avatar">
                        </div>

                        @include('livewire.users.partials.form')

                    </div>

                    {{-- WORK TAB --}}
                    <div class="tab-pane fade" id="work">

                        <div class="row">

                            <div class="col-md-6 mb-3">
                                <label>Directorate</label>
                                <input type="text" class="form-control" wire:model="directorate">
                            </div>

                            <div class="col-md-6 mb-3">
                                <label>Job Title</label>
                                <input type="text" class="form-control" wire:model="job_title">
                            </div>

                            <div class="col-md-6 mb-3">
                                <label>Department</label>
                                <input type="text" class="form-control" wire:model="section">
                            </div>

                            <div class="col-md-6 mb-3">
                                <label>Station</label>
                                <input type="text" class="form-control" wire:model="station">
                            </div>

                        </div>

                    </div>

                    {{-- SECURITY TAB --}}
                    <div class="tab-pane fade" id="security">

                        <div class="row">

                            <div class="col-md-6 mb-3">
                                <label>Password</label>
                                <input type="password" class="form-control" wire:model="password">
                            </div>

                            <div class="col-md-6 mb-3">
                                <label>Confirm Password</label>
                                <input type="password" class="form-control" wire:model="password_confirmation">
                            </div>

                            <div class="col-md-6 mb-3">
                                <label>Status</label>
                                <select class="form-control" wire:model="is_banned">
                                    <option value="0">Active</option>
                                    <option value="1">Banned</option>
                                </select>
                            </div>

                        </div>

                    </div>

                </div>

                {{-- ACTIONS --}}
                <div class="d-flex justify-content-end mt-4">

                    <a href="{{ route('users.index') }}"
                       class="btn btn-light mr-2">
                        Cancel
                    </a>

                    <button class="btn text-white px-4"
                            style="background: {{ config('theme.color_accent') }}"
                            wire:loading.attr="disabled">

                        <span wire:loading.remove>
                            <i class="fas fa-save mr-1"></i> Update User
                        </span>

                        <span wire:loading>
                            <span class="spinner-border spinner-border-sm"></span>
                        </span>

                    </button>

                </div>

            </form>

        </div>
    </div>
</div>
