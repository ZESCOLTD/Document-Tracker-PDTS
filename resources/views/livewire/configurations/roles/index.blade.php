<div>

    <!-- ===========================================
        PAGE HEADER
    ============================================ -->

    <div class="d-flex justify-content-between align-items-center mb-4">

        <div>

            <h3 class="fw-bold mb-1">

                Roles & Permissions

            </h3>

            <p class="text-muted mb-0">

                Manage system roles and access permissions.

            </p>

        </div>

        <button
            class="btn btn-primary"
            wire:click="create">

            <i class="bi bi-plus-circle me-2"></i>

            New Role

        </button>

    </div>

    @if(session()->has('success'))

        <div class="alert alert-success alert-dismissible fade show">

            {{ session('success') }}

            <button class="btn-close"
                    data-bs-dismiss="alert"></button>

        </div>

    @endif


    <!-- ===========================================
        DASHBOARD
    ============================================ -->

    <div class="row mb-4">

        <div class="col-lg-4">

            <div class="card border-0 shadow-sm">

                <div class="card-body">

                    <small class="text-muted">

                        Total Roles

                    </small>

                    <h2 class="fw-bold">

                        {{ $roles->total() }}

                    </h2>

                </div>

            </div>

        </div>

        <div class="col-lg-4">

            <div class="card border-0 shadow-sm">

                <div class="card-body">

                    <small class="text-muted">

                        Permissions

                    </small>

                    <h2 class="fw-bold text-primary">

                        {{ $permissions->count() }}

                    </h2>

                </div>

            </div>

        </div>

        <div class="col-lg-4">

            <div class="card border-0 shadow-sm">

                <div class="card-body">

                    <small class="text-muted">

                        Users Assigned

                    </small>

                    <h2 class="fw-bold text-success">

                        {{ \App\Models\User::count() }}

                    </h2>

                </div>

            </div>

        </div>

    </div>


    <!-- ===========================================
        FILTERS
    ============================================ -->

    <div class="card border-0 shadow-sm mb-4">

        <div class="card-body">

            <div class="row">

                <div class="col-md-10">

                    <input
                        type="text"
                        class="form-control"
                        placeholder="Search role..."
                        wire:model.live.debounce.500ms="search">

                </div>

                <div class="col-md-2">

                    <select
                        class="form-select"
                        wire:model.live="perPage">

                        <option>10</option>
                        <option>25</option>
                        <option>50</option>
                        <option>100</option>

                    </select>

                </div>

            </div>

        </div>

    </div>


    <!-- ===========================================
        TABLE
    ============================================ -->

    <div class="card border-0 shadow-sm">

        <div class="table-responsive">

            <table class="table table-hover align-middle mb-0">

                <thead class="table-light">

                <tr>

                    <th>Role</th>

                    <th>Users</th>

                    <th>Permissions</th>

                    <th>Created</th>

                    <th width="160">

                        Actions

                    </th>

                </tr>

                </thead>

                <tbody>

                @forelse($roles as $role)

                    <tr>

                        <td>

                            <strong>

                                {{ $role->name }}

                            </strong>

                        </td>

                        <td>

                            <span class="badge bg-primary">

                                {{ $role->users_count }}

                            </span>

                        </td>

                        <td>

                            <span class="badge bg-success">

                                {{ $role->permissions()->count() }}

                            </span>

                        </td>

                        <td>

                            {{ $role->created_at->format('d M Y') }}

                        </td>

                        <td>

                            <div class="btn-group">

                                <button
                                    class="btn btn-sm btn-warning"
                                    wire:click="edit({{ $role->id }})">

                                    <i class="bi bi-pencil"></i>

                                </button>

                                <button
                                    class="btn btn-sm btn-danger"
                                    wire:click="delete({{ $role->id }})"
                                    onclick="return confirm('Delete this role?')">

                                    <i class="bi bi-trash"></i>

                                </button>

                            </div>

                        </td>

                    </tr>

                @empty

                    <tr>

                        <td colspan="5"
                            class="text-center py-5">

                            <i class="bi bi-shield-lock display-4 text-muted"></i>

                            <h5 class="mt-3">

                                No Roles Found

                            </h5>

                            <p class="text-muted">

                                Create your first role.

                            </p>

                        </td>

                    </tr>

                @endforelse

                </tbody>

            </table>

        </div>

        <div class="card-footer bg-white">

            {{ $roles->links() }}

        </div>

    </div>


    <!-- ===========================================
        MODALS
    ============================================ -->

    @include('livewire.configurations.roles.partials.create-modal')

    @include('livewire.configurations.roles.partials.edit-modal')

</div>


{{--@script--}}

<script>

    document.addEventListener('livewire:init', () => {

        const createModal = new bootstrap.Modal(
            document.getElementById('createModal')
        );

        const editModal = new bootstrap.Modal(
            document.getElementById('editModal')
        );

        Livewire.on('show-create-modal', () => {

            createModal.show();

        });

        Livewire.on('show-edit-modal', () => {

            editModal.show();

        });

        Livewire.on('close-modal', () => {

            createModal.hide();

            editModal.hide();

        });

    });

</script>

{{--@endscript--}}
