<div class="container py-4">

    {{-- Header --}}
    <div class="mb-4">
        <h4 class="fw-semibold mb-1">Create User</h4>
        <small class="text-muted">Add a new system user</small>
    </div>

    <form wire:submit.prevent="save">

        {{-- PERSONAL INFO --}}
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-light fw-semibold">Personal Information</div>

            <div class="card-body row g-3">

                <div class="col-md-6">
                    <label class="form-label">Full Name</label>
                    <input type="text" class="form-control" wire:model="name">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" wire:model="email">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Mobile Number</label>
                    <input type="text" class="form-control" wire:model="mobile_no">
                </div>

                <div class="col-md-6">
                    <label class="form-label">NRC</label>
                    <input type="text" class="form-control" wire:model="nrc">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Date of Birth</label>
                    <input type="date" class="form-control" wire:model="dob">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Sex</label>
                    <select class="form-select" wire:model="sex">
                        <option value="">Select</option>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                    </select>
                </div>

            </div>
        </div>

        {{-- WORK INFO --}}
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-light fw-semibold">Work Information</div>

            <div class="card-body row g-3">

                <div class="col-md-6">
                    <label class="form-label">Man No</label>
                    <input type="text" class="form-control" wire:model="man_no">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Position</label>
                    <input type="text" class="form-control" wire:model="position">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Directorate</label>
                    <input type="text" class="form-control" wire:model="directorate">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Division</label>
                    <input type="text" class="form-control" wire:model="division">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Station</label>
                    <input type="text" class="form-control" wire:model="station">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Location</label>
                    <input type="text" class="form-control" wire:model="location">
                </div>

            </div>
        </div>

        {{-- SYSTEM INFO --}}
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-light fw-semibold">System Access</div>

            <div class="card-body row g-3">

                <div class="col-md-6">
                    <label class="form-label">Role</label>
                    <select class="form-select" wire:model="role_id">
                        <option value="">Select Role</option>
                        @foreach($roles as $role)
                            <option value="{{ $role->id }}">{{ $role->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Password</label>
                    <input type="password" class="form-control" wire:model="password">
                </div>

            </div>
        </div>

        {{-- ACTIONS --}}
        <div class="d-flex justify-content-end gap-2">
            <a href="{{ route('users.index') }}" class="btn btn-light">Cancel</a>
            <button class="btn btn-primary px-4">Create User</button>
        </div>

    </form>
</div>
