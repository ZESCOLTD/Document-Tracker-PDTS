<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start mb-4">
        <div>
            <h1 class="h3 mb-1">Department Management</h1>
            <p class="text-muted mb-0">Maintain the organisational department hierarchy.</p>
        </div>
        <div class="btn-group" role="group" aria-label="Organizational management sections">
            <a href="{{ route('pdts.organization.departments') }}" class="btn btn-primary">Departments</a>
            <a href="{{ route('pdts.organization.offices') }}" class="btn btn-outline-secondary">Offices</a>
            <a href="{{ route('pdts.organization.assignments') }}" class="btn btn-outline-secondary">Employee Assignments</a>
        </div>
    </div>

    @if (session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif
    @if (session('error')) <div class="alert alert-warning">{{ session('error') }}</div> @endif

    <section class="bg-white border rounded p-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="h5 mb-0">Departments</h2>
            <button type="button" class="btn btn-primary btn-sm ml-auto mr-2" wire:click="openCreate"><i class="fas fa-plus mr-1"></i>Add department</button>
            <input type="search" class="form-control" style="max-width: 320px" placeholder="Search departments" wire:model.live.debounce.300ms="search">
        </div>
        <div class="table-responsive actions-overflow">
            <table class="table table-hover mb-0">
                <thead>
                    <tr><th>Code</th><th>Name</th><th>Parent</th><th>Offices</th><th>Status</th><th class="text-right">Actions</th></tr>
                </thead>
                <tbody>
                    @forelse($departments as $department)
                        <tr>
                            <td>{{ $department->code ?: '—' }}</td>
                            <td>{{ $department->name }}</td>
                            <td>{{ $department->parent?->name ?: '—' }}</td>
                            <td>{{ $department->offices_count }}</td>
                            <td><span class="badge {{ $department->is_active ? 'badge-success' : 'badge-secondary' }}">{{ $department->is_active ? 'Active' : 'Inactive' }}</span></td>
                            <td class="text-right">
                                <x-pdts.row-actions>
                                    <x-pdts.row-action icon="fa-pen" wire:click="edit({{ $department->id }})">Edit</x-pdts.row-action>
                                    <x-pdts.row-action icon="{{ $department->is_active ? 'fa-toggle-off' : 'fa-toggle-on' }}" wire:click="toggleActive({{ $department->id }})">{{ $department->is_active ? 'Deactivate' : 'Activate' }}</x-pdts.row-action>
                                </x-pdts.row-actions>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">No departments found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $departments->links() }}</div>
    </section>
    @if ($showForm)
        <x-pdts.form-modal :title="$editingId ? 'Edit department' : 'Add department'" :submit="$editingId ? 'Save changes' : 'Add department'">
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="department-code">Code <span class="text-muted">(optional)</span></label>
                        <input id="department-code" class="form-control" wire:model="code" maxlength="64">
                        @error('code') <small class="text-danger">{{ $message }}</small> @enderror
                    </div>
                    <div class="form-group col-md-6">
                        <label for="department-name">Department name</label>
                        <input id="department-name" class="form-control" wire:model="name" maxlength="191" required>
                        @error('name') <small class="text-danger">{{ $message }}</small> @enderror
                    </div>
                    <div class="form-group col-md-6">
                        <label for="department-parent">Parent department</label>
                        <select id="department-parent" class="form-control" wire:model="parentDepartmentId">
                            <option value="">None</option>
                            @foreach($parentOptions as $parent)
                                <option value="{{ $parent->id }}">{{ $parent->name }}</option>
                            @endforeach
                        </select>
                        @error('parentDepartmentId') <small class="text-danger">{{ $message }}</small> @enderror
                    </div>
                    <div class="form-group col-md-6 d-flex align-items-end">
                        <div class="custom-control custom-switch mb-2">
                            <input id="department-active" type="checkbox" class="custom-control-input" wire:model="isActive">
                            <label class="custom-control-label" for="department-active">Active</label>
                        </div>
                    </div>
                </div>
                @error('isActive') <div class="text-danger mb-2">{{ $message }}</div> @enderror
        </x-pdts.form-modal>
    @endif
</div>
