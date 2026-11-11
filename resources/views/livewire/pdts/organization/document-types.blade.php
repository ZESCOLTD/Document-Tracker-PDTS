<div class="container-fluid py-4">
    <div class="mb-4">
        <h1 class="h3 mb-1">Document Types</h1>
        <p class="text-muted mb-0">The types people choose from when registering a document. Only active types are offered.</p>
    </div>

    @if (session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif
    @if (session('error')) <div class="alert alert-warning">{{ session('error') }}</div> @endif

    <section class="bg-white border rounded p-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap mb-3" style="gap: .5rem;">
            <h2 class="h5 mb-0">Document types</h2>
            <button type="button" class="btn btn-primary btn-sm ml-auto mr-2" wire:click="openCreate"><i class="fas fa-plus mr-1"></i>Add document type</button>
            <input type="search" class="form-control" style="max-width: 320px" placeholder="Search document types" aria-label="Search document types" wire:model.live.debounce.300ms="search">
        </div>
        <div class="table-responsive actions-overflow">
            <table class="table table-hover mb-0">
                <thead>
                    <tr><th>Name</th><th>Documents</th><th>Status</th><th class="text-right">Actions</th></tr>
                </thead>
                <tbody>
                    @forelse ($types as $type)
                        <tr wire:key="type-{{ $type->id }}">
                            <td>{{ $type->name }}</td>
                            <td>{{ $type->documents_count }}</td>
                            <td><span class="badge {{ $type->is_active ? 'badge-success' : 'badge-secondary' }}">{{ $type->is_active ? 'Active' : 'Inactive' }}</span></td>
                            <td class="text-right">
                                <x-pdts.row-actions>
                                    <x-pdts.row-action icon="fa-pen" wire:click="edit({{ $type->id }})">Edit</x-pdts.row-action>
                                    <x-pdts.row-action icon="{{ $type->is_active ? 'fa-toggle-off' : 'fa-toggle-on' }}" wire:click="toggleActive({{ $type->id }})">{{ $type->is_active ? 'Deactivate' : 'Activate' }}</x-pdts.row-action>
                                    @if ($type->documents_count === 0)
                                        <div class="dropdown-divider"></div>
                                        <x-pdts.row-action icon="fa-trash-alt" danger wire:click="delete({{ $type->id }})" wire:confirm="Delete {{ $type->name }}?">Delete</x-pdts.row-action>
                                    @endif
                                </x-pdts.row-actions>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-4">No document types found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $types->links() }}</div>
    </section>
    @if ($showForm)
        <x-pdts.form-modal :title="$editingId ? 'Edit document type' : 'Add document type'" :submit="$editingId ? 'Save changes' : 'Add document type'">
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="type-name">Name</label>
                        <input id="type-name" class="form-control @error('name') is-invalid @enderror" wire:model="name" maxlength="120" required>
                        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="form-group col-md-6 d-flex align-items-end">
                        <div class="custom-control custom-switch mb-2">
                            <input id="type-active" type="checkbox" class="custom-control-input" wire:model="isActive">
                            <label class="custom-control-label" for="type-active">Active</label>
                        </div>
                    </div>
                </div>
        </x-pdts.form-modal>
    @endif
</div>
