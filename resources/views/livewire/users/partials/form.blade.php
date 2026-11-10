{{-- PERSONAL --}}
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
            <label class="form-label">Mobile</label>
            <input type="text" class="form-control" wire:model="mobile_no">
        </div>

        <div class="col-md-6">
            <label class="form-label">Man No</label>
            <input type="text" class="form-control" wire:model="man_no">
        </div>

    </div>
</div>

{{-- SYSTEM --}}
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-light fw-semibold">System Access</div>

    <div class="card-body row g-3">

        <div class="col-md-6">
            <label class="form-label">Role</label>
            <select class="form-select form-control @error('role') is-invalid @enderror" wire:model="role">
                <option value="">No role (no access)</option>
                @foreach($roles as $roleOption)
                    <option value="{{ $roleOption->name }}">{{ $roleOption->name }}</option>
                @endforeach
            </select>
            @error('role') <div class="invalid-feedback">{{ $message }}</div> @enderror
            <small class="form-text text-muted">Controls what this user can see and do.</small>
        </div>

        <div class="col-md-6">
            <label class="form-label">Password (optional)</label>
            <input type="password" class="form-control" wire:model="password">
        </div>

    </div>
</div>
