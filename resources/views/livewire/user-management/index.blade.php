<div>
    <x-slot name="title">User Management</x-slot>
{{--    <x-slot name="pageHeader">User Management</x-slot>--}}

    <div class="container-fluid">

        {{-- HEADER --}}
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <h4 class="mb-1 fw-semibold">Users</h4>
                <small class="text-muted">Manage users, roles, and access control</small>
            </div>

            <div class="d-flex gap-2">

                {{-- SEARCH --}}
                <input type="text"
                       class="form-control mr-2"
                       placeholder="Search name, email, man no..."
                       style="width: 250px;"
                       wire:model.debounce.500ms="search">

                {{-- ROLE FILTER --}}
                <select class="form-select"
                        wire:model="roleFilter"
                        style="width: 180px;">
                    <option value="">All Roles</option>
                    @foreach($roles as $role)
                        <option value="{{ $role->id }}">{{ $role->name }}</option>
                    @endforeach
                </select>

                {{-- STATUS FILTER --}}
                <select class="form-select"
                        wire:model="statusFilter"
                        style="width: 150px;">
                    <option value="">All Status</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>

                <a href="{{ route('users.create') }}" class="btn btn-primary">
                    + New User
                </a>
            </div>
        </div>

        {{-- ANALYTICS --}}
        <div class="row mb-4">

            <div class="col-md-3">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <small class="text-muted">Total Users</small>
                        <h3 class="mb-1">{{ $totalUsers }}</h3>
                        <small class="text-success">+{{ $newUsersThisMonth }} this month</small>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <small class="text-muted">Active Users</small>
                        <h3 class="mb-1 text-success">{{ $activeUsers }}</h3>
                        <small class="text-muted">{{ $activePercentage }}% of total</small>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <small class="text-muted">Admins</small>
                        <h3 class="mb-1 text-primary">{{ $adminUsers }}</h3>
                        <small class="text-muted">Privileged users</small>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <small class="text-muted">Banned</small>
                        <h3 class="mb-1 text-danger">{{ $bannedUsers }}</h3>
                        <small class="text-muted">Restricted access</small>
                    </div>
                </div>
            </div>

        </div>

        {{-- TABLE --}}
        <div class="card shadow-sm border-0">

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">

                    <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>User</th>
                        <th>Contact</th>
                        <th>Organization</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                    </thead>

                    <tbody>
                    @forelse($users as $index => $user)
                        <tr>

                            <td>{{ $users->firstItem() + $index }}</td>

                            {{-- USER WITH AVATAR --}}
                            <td>
                                <div class="d-flex align-items-center gap-3">

                                    {{-- Avatar --}}
                                    <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center"
                                         style="width: 40px; height: 40px; font-weight: bold;">
                                        {{ strtoupper(substr($user->name, 0, 2)) }}
                                    </div>

                                    <div>
                                        <div class="fw-semibold">{{ $user->name }}</div>
                                        <small class="text-muted">{{ $user->man_no ?? '—' }}</small>
                                    </div>

                                </div>
                            </td>

                            {{-- CONTACT --}}
                            <td>
                                <div>{{ $user->email }}</div>
                                <small class="text-muted">{{ $user->mobile_no }}</small>
                            </td>

                            {{-- ORG --}}
                            <td>
                                <div>{{ $user->directorate }}</div>
                                <small class="text-muted">{{ $user->department ?? $user->division }}</small>
                            </td>

                            {{-- ROLE --}}
                            <td>
                                @php
                                    $roleColors = [
                                        'Admin' => 'danger',
                                        'Manager' => 'primary',
                                        'User' => 'secondary',
                                    ];
                                @endphp

                                <span class="badge bg-{{ $roleColors[$user->role->name ?? 'User'] ?? 'secondary' }}">
                                    {{ $user->role->name ?? 'User' }}
                                </span>
                            </td>

                            {{-- STATUS TOGGLE --}}
                            <td>
                                <div class="form-check form-switch">
                                    <input class="form-check-input"
                                           type="checkbox"
                                           wire:change="toggleStatus({{ $user->id }})"
                                        {{ !$user->is_banned ? 'checked' : '' }}>
                                </div>
                            </td>

                            {{-- ACTIONS --}}
                            <td class="text-center">
                                <div class="btn-group">
                                    <a href="{{ route('users.show', $user->id) }}"
                                       class="btn btn-sm btn-outline-primary">View</a>

                                    <a href="{{ route('users.edit', $user->id) }}"
                                       class="btn btn-sm btn-outline-warning">Edit</a>

                                    <button wire:click="confirmDelete({{ $user->id }})"
                                            class="btn btn-sm btn-outline-danger">
                                        Delete
                                    </button>
                                </div>
                            </td>

                        </tr>

                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
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
</div>
