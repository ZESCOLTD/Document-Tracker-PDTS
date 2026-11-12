<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start mb-4">
        <div>
            <h1 class="h3 mb-1">Office Management</h1>
            <p class="text-muted mb-0">An office sits under a department, or directly under a directorate (such as the Director's office).</p>
        </div>
        <div class="btn-group" role="group" aria-label="Organizational management sections">
            <a href="{{ route('pdts.organization.directorates') }}" class="btn btn-outline-secondary">Directorates</a>
            <a href="{{ route('pdts.organization.departments') }}" class="btn btn-outline-secondary">Departments</a>
            <a href="{{ route('pdts.organization.offices') }}" class="btn btn-primary">Offices</a>
            <a href="{{ route('pdts.organization.assignments') }}" class="btn btn-outline-secondary">Employee Assignments</a>
        </div>
    </div>

    @if (session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif
    @if (session('error')) <div class="alert alert-warning">{{ session('error') }}</div> @endif

    <section class="bg-white border rounded p-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="h5 mb-0">Offices</h2>
            <button type="button" class="btn btn-primary btn-sm ml-auto mr-2" wire:click="openCreate"><i class="fas fa-plus mr-1"></i>Add office</button>
            <input type="search" class="form-control" style="max-width: 320px" placeholder="Search offices, departments or directorates" wire:model.live.debounce.300ms="search">
        </div>
        <div class="table-responsive actions-overflow">
            <table class="table table-hover mb-0">
                <thead>
                    <tr><th>Code</th><th>Office</th><th>Department</th><th>Directorate</th><th>Parent office</th><th>Active assignments</th><th>Status</th><th class="text-right">Actions</th></tr>
                </thead>
                <tbody>
                    @forelse($offices as $office)
                        <tr>
                            <td>{{ $office->code ?: '—' }}</td>
                            <td>
                                {{ $office->name }}
                                @if ($office->is_head)
                                    <span class="badge badge-info ml-1">Head</span>
                                @endif
                            </td>
                            <td>
                                @if ($office->department)
                                    {{ $office->department->name }}
                                @else
                                    <span class="text-muted">Directorate office</span>
                                @endif
                            </td>
                            <td>{{ $office->owningDirectorate()?->name ?? '—' }}</td>
                            <td>{{ $office->parent?->name ?: '—' }}</td>
                            <td>{{ $office->assignments_count }}</td>
                            <td><span class="badge {{ $office->is_active ? 'badge-success' : 'badge-secondary' }}">{{ $office->is_active ? 'Active' : 'Inactive' }}</span></td>
                            <td class="text-right">
                                <x-pdts.row-actions>
                                    <x-pdts.row-action icon="fa-pen" wire:click="edit({{ $office->id }})">Edit</x-pdts.row-action>
                                    <x-pdts.row-action icon="{{ $office->is_active ? 'fa-toggle-off' : 'fa-toggle-on' }}" wire:click="toggleActive({{ $office->id }})">{{ $office->is_active ? 'Deactivate' : 'Activate' }}</x-pdts.row-action>
                                </x-pdts.row-actions>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted py-4">No offices found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $offices->links() }}</div>
    </section>
    @if ($showForm)
        <x-pdts.form-modal :title="$editingId ? 'Edit office' : 'Add office'" :submit="$editingId ? 'Save changes' : 'Add office'">
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="office-code">Code <span class="text-muted">(optional)</span></label>
                        <input id="office-code" class="form-control" wire:model="code" maxlength="64">
                        @error('code') <small class="text-danger">{{ $message }}</small> @enderror
                    </div>
                    <div class="form-group col-md-6">
                        <label for="office-name">Office name</label>
                        <input id="office-name" class="form-control" wire:model="name" maxlength="191" required>
                        @error('name') <small class="text-danger">{{ $message }}</small> @enderror
                    </div>
                    <div class="form-group col-md-6">
                        <label for="office-directorate">Directorate</label>
                        <select id="office-directorate" class="form-control" wire:model.live="directorateId" required>
                            <option value="">Select a directorate</option>
                            @foreach($directorates as $directorate)
                                <option value="{{ $directorate->id }}">{{ $directorate->name }}</option>
                            @endforeach
                        </select>
                        @error('directorateId') <small class="text-danger">{{ $message }}</small> @enderror
                    </div>
                    <div class="form-group col-md-6">
                        <label for="office-department">Department</label>
                        <select id="office-department" class="form-control" wire:model.live="departmentId" @disabled($directorateId === '')>
                            <option value="">{{ $directorateId === '' ? 'Choose a directorate first' : 'None — office of the directorate itself' }}</option>
                            @foreach($departments as $department)
                                <option value="{{ $department->id }}">{{ $department->name }}</option>
                            @endforeach
                        </select>
                        <small class="form-text text-muted">Leave empty for the Director's office and other offices that sit directly under the directorate.</small>
                        @error('departmentId') <small class="text-danger">{{ $message }}</small> @enderror
                    </div>
                    <div class="form-group col-md-6">
                        <label for="office-parent">Parent office</label>
                        <select id="office-parent" class="form-control" wire:model="parentOfficeId">
                            <option value="">None</option>
                            @foreach($parentOffices as $parent)
                                <option value="{{ $parent->id }}">{{ $parent->name }}</option>
                            @endforeach
                        </select>
                        @error('parentOfficeId') <small class="text-danger">{{ $message }}</small> @enderror
                    </div>
                    <div class="form-group col-md-6 d-flex flex-column justify-content-end">
                        <div class="custom-control custom-switch mb-2">
                            <input id="office-head" type="checkbox" class="custom-control-input" wire:model="isHead">
                            <label class="custom-control-label" for="office-head">
                                Head of the {{ $departmentId === '' ? 'directorate' : 'department' }}
                            </label>
                        </div>
                        <div class="custom-control custom-switch mb-2">
                            <input id="office-active" type="checkbox" class="custom-control-input" wire:model="isActive">
                            <label class="custom-control-label" for="office-active">Active</label>
                        </div>
                    </div>
                </div>
                @error('isHead') <div class="text-danger mb-2">{{ $message }}</div> @enderror
                @error('isActive') <div class="text-danger mb-2">{{ $message }}</div> @enderror
        </x-pdts.form-modal>
    @endif
</div>
