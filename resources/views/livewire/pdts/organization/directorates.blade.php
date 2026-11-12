<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start mb-4">
        <div>
            <h1 class="h3 mb-1">Directorate Management</h1>
            <p class="text-muted mb-0">Directorates are the top level of the organisation. Each department belongs to one directorate.</p>
        </div>
        <div class="btn-group" role="group" aria-label="Organizational management sections">
            <a href="{{ route('pdts.organization.directorates') }}" class="btn btn-primary">Directorates</a>
            <a href="{{ route('pdts.organization.departments') }}" class="btn btn-outline-secondary">Departments</a>
            <a href="{{ route('pdts.organization.offices') }}" class="btn btn-outline-secondary">Offices</a>
            <a href="{{ route('pdts.organization.assignments') }}" class="btn btn-outline-secondary">Employee Assignments</a>
        </div>
    </div>

    @if (session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif
    @if (session('error')) <div class="alert alert-warning">{{ session('error') }}</div> @endif

    <section class="bg-white border rounded p-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="h5 mb-0">Directorates</h2>
            <button type="button" class="btn btn-primary btn-sm ml-auto mr-2" wire:click="openCreate"><i class="fas fa-plus mr-1"></i>Add directorate</button>
            <input type="search" class="form-control" style="max-width: 320px" placeholder="Search directorates" aria-label="Search directorates" wire:model.live.debounce.300ms="search">
        </div>
        <div class="table-responsive actions-overflow">
            <table class="table table-hover mb-0">
                <thead>
                    <tr><th>Code</th><th>Name</th><th>Departments</th><th>Status</th><th class="text-right">Actions</th></tr>
                </thead>
                <tbody>
                    @forelse($directorates as $directorate)
                        <tr>
                            <td>{{ $directorate->code ?: '—' }}</td>
                            <td>{{ $directorate->name }}</td>
                            <td>{{ $directorate->departments_count }}</td>
                            <td><span class="badge {{ $directorate->is_active ? 'badge-success' : 'badge-secondary' }}">{{ $directorate->is_active ? 'Active' : 'Inactive' }}</span></td>
                            <td class="text-right">
                                <x-pdts.row-actions>
                                    <x-pdts.row-action icon="fa-pen" wire:click="edit({{ $directorate->id }})">Edit</x-pdts.row-action>
                                    <x-pdts.row-action icon="{{ $directorate->is_active ? 'fa-toggle-off' : 'fa-toggle-on' }}" wire:click="toggleActive({{ $directorate->id }})">{{ $directorate->is_active ? 'Deactivate' : 'Activate' }}</x-pdts.row-action>
                                </x-pdts.row-actions>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">No directorates found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $directorates->links() }}</div>
    </section>
    @if ($showForm)
        <x-pdts.form-modal :title="$editingId ? 'Edit directorate' : 'Add directorate'" :submit="$editingId ? 'Save changes' : 'Add directorate'">
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="directorate-code">Code <span class="text-muted">(optional)</span></label>
                        <input id="directorate-code" class="form-control" wire:model="code" maxlength="64">
                        @error('code') <small class="text-danger">{{ $message }}</small> @enderror
                    </div>
                    <div class="form-group col-md-6">
                        <label for="directorate-name">Directorate name</label>
                        <input id="directorate-name" class="form-control" wire:model="name" maxlength="191" required>
                        @error('name') <small class="text-danger">{{ $message }}</small> @enderror
                    </div>
                    <div class="form-group col-md-6 d-flex align-items-end">
                        <div class="custom-control custom-switch mb-2">
                            <input id="directorate-active" type="checkbox" class="custom-control-input" wire:model="isActive">
                            <label class="custom-control-label" for="directorate-active">Active</label>
                        </div>
                    </div>
                </div>
                @error('isActive') <div class="text-danger mb-2">{{ $message }}</div> @enderror
        </x-pdts.form-modal>
    @endif
</div>
