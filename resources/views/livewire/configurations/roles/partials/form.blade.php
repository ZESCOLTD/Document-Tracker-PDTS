<div class="row g-4">

    <!-- ===========================================
         ROLE DETAILS
    ============================================ -->

    <div class="col-12">

        <div class="card border-0 shadow-sm">

            <div class="card-header bg-white">

                <strong>

                    <i class="bi bi-shield-lock me-2"></i>

                    Role Information

                </strong>

            </div>

            <div class="card-body">

                <div class="row">

                    <div class="col-md-6">

                        <label class="form-label">

                            Role Name <span class="text-danger">*</span>

                        </label>

                        <input
                            type="text"
                            class="form-control"
                            wire:model="name"
                            placeholder="e.g Branch Manager">

                        @error('name')

                        <small class="text-danger">

                            {{ $message }}

                        </small>

                        @enderror

                    </div>

                </div>

            </div>

        </div>

    </div>

    <!-- ===========================================
         PERMISSIONS
    ============================================ -->

    <div class="col-12">

        <div class="card border-0 shadow-sm">

            <div class="card-header bg-white d-flex justify-content-between align-items-center">

                <strong>

                    <i class="bi bi-key-fill me-2"></i>

                    Permissions

                </strong>

                <div>

                    <button
                        type="button"
                        class="btn btn-sm btn-outline-primary"
                        wire:click="selectAllPermissions">

                        Select All

                    </button>

                    <button
                        type="button"
                        class="btn btn-sm btn-outline-secondary"
                        wire:click="clearPermissions">

                        Clear

                    </button>

                </div>

            </div>

            <div class="card-body">

                @foreach($permissionGroups as $module => $permissions)

                    <div class="card mb-3 border">

                        <div class="card-header bg-light">

                            <strong>

                                {{ ucwords(str_replace('_',' ',$module)) }}

                            </strong>

                        </div>

                        <div class="card-body">

                            <div class="row">

                                @foreach($permissions as $permission)

                                    @php

                                        $action = explode('.', $permission->name)[1] ?? '';

                                    @endphp

                                    <div class="col-lg-3 col-md-4 col-sm-6 mb-3">

                                        <div class="form-check">

                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                value="{{ $permission->name }}"
                                                wire:model="selectedPermissions"
                                                id="{{ md5($permission->name) }}">

                                            <label
                                                class="form-check-label"
                                                for="{{ md5($permission->name) }}">

                                                {{ ucfirst($action) }}

                                            </label>

                                        </div>

                                    </div>

                                @endforeach

                            </div>

                        </div>

                    </div>

                @endforeach

                @error('selectedPermissions')

                <small class="text-danger">

                    {{ $message }}

                </small>

                @enderror

            </div>

        </div>

    </div>

</div>
