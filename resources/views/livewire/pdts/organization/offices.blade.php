<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start mb-4">
        <div>
            <h1 class="h3 mb-1">Office Management</h1>
            <p class="text-muted mb-0">Maintain physical offices within their departments.</p>
        </div>
        <div class="btn-group" role="group" aria-label="Organizational management sections">
            <a href="{{ route('pdts.organization.departments') }}" class="btn btn-outline-secondary">Departments</a>
            <a href="{{ route('pdts.organization.offices') }}" class="btn btn-primary">Offices</a>
            <a href="{{ route('pdts.organization.assignments') }}" class="btn btn-outline-secondary">Employee Assignments</a>
        </div>
    </div>

    @if (session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif
    @if (session('error')) <div class="alert alert-warning">{{ session('error') }}</div> @endif

    <section class="bg-white border rounded p-4 mb-4">
        <h2 class="h5 mb-3">{{ $editingId ? 'Edit office' : 'Add office' }}</h2>
        <form wire:submit.prevent="save">
            <div class="form-row">
                <div class="form-group col-md-2">
                    <label for="office-code">Code <span class="text-muted">(optional)</span></label>
                    <input id="office-code" class="form-control" wire:model="code" maxlength="64">
                    @error('code') <small class="text-danger">{{ $message }}</small> @enderror
                </div>
                <div class="form-group col-md-3">
                    <label for="office-name">Office name</label>
                    <input id="office-name" class="form-control" wire:model="name" maxlength="191" required>
                    @error('name') <small class="text-danger">{{ $message }}</small> @enderror
                </div>
                <div class="form-group col-md-3">
                    <label for="office-department">Department</label>
                    <select id="office-department" class="form-control" wire:model.live="departmentId" required>
                        <option value="">Select a department</option>
                        @foreach($departments as $department)
                            <option value="{{ $department->id }}">{{ $department->name }}</option>
                        @endforeach
                    </select>
                    @error('departmentId') <small class="text-danger">{{ $message }}</small> @enderror
                </div>
                <div class="form-group col-md-2">
                    <label for="office-parent">Parent office</label>
                    <select id="office-parent" class="form-control" wire:model="parentOfficeId">
                        <option value="">None</option>
                        @foreach($parentOffices as $parent)
                            <option value="{{ $parent->id }}">{{ $parent->name }}</option>
                        @endforeach
                    </select>
                    @error('parentOfficeId') <small class="text-danger">{{ $message }}</small> @enderror
                </div>
                <div class="form-group col-md-2 d-flex align-items-end">
                    <div class="custom-control custom-switch mb-2">
                        <input id="office-active" type="checkbox" class="custom-control-input" wire:model="isActive">
                        <label class="custom-control-label" for="office-active">Active</label>
                    </div>
                </div>
            </div>
            @error('isActive') <div class="text-danger mb-2">{{ $message }}</div> @enderror
            <div class="d-flex justify-content-end">
                @if($editingId)
                    <button type="button" class="btn btn-outline-secondary mr-2" wire:click="cancelEdit">Cancel</button>
                @endif
                <button type="submit" class="btn btn-primary">{{ $editingId ? 'Save changes' : 'Add office' }}</button>
            </div>
        </form>
    </section>

    <section class="bg-white border rounded p-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="h5 mb-0">Offices</h2>
            <input type="search" class="form-control" style="max-width: 320px" placeholder="Search offices" wire:model.live.debounce.300ms="search">
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr><th>Code</th><th>Office</th><th>Department</th><th>Parent office</th><th>Active assignments</th><th>Status</th><th class="text-right">Actions</th></tr>
                </thead>
                <tbody>
                    @forelse($offices as $office)
                        <tr>
                            <td>{{ $office->code ?: '—' }}</td>
                            <td>{{ $office->name }}</td>
                            <td>{{ $office->department->name }}</td>
                            <td>{{ $office->parent?->name ?: '—' }}</td>
                            <td>{{ $office->assignments_count }}</td>
                            <td><span class="badge {{ $office->is_active ? 'badge-success' : 'badge-secondary' }}">{{ $office->is_active ? 'Active' : 'Inactive' }}</span></td>
                            <td class="text-right">
                                <button type="button" class="btn btn-sm btn-outline-primary" wire:click="edit({{ $office->id }})">Edit</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="toggleActive({{ $office->id }})">{{ $office->is_active ? 'Deactivate' : 'Activate' }}</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">No offices found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $offices->links() }}</div>
    </section>
</div>
