<div>
    <x-slot name="title">Workflow Definitions</x-slot>
    <x-slot name="pageHeader">Workflow Definitions</x-slot>

    <div class="container-fluid">

        {{-- PAGE HEADER --}}
        <div class="card mb-4 border-0 shadow-sm">
            <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-3">

                <div>
                    <h4 class="mb-1 fw-bold">
                        Workflow Definitions
                    </h4>

                    <small class="text-muted">
                        Create and manage workflow definitions
                    </small>
                </div>

                <button class="btn btn-primary"
                        data-toggle="modal"
                        data-target="#createWorkflowModal">

                    <i class="fas fa-plus-circle mr-1"></i>
                    New Workflow
                </button>

            </div>
        </div>

        {{-- WORKFLOW LIST --}}
        <div class="card border-0 shadow-sm">

            <div class="card-header bg-light d-flex justify-content-between align-items-center">

                <h5 class="mb-0">
                    Workflow Listing
                </h5>

                <div class="d-flex align-items-center gap-2">
                    <input type="text"
                           wire:model.live.debounce.500ms="search"
                           class="form-control"
                           placeholder="Search workflow..."
                           style="width: 250px;">
                </div>

            </div>

            <div class="card-body">

                <div class="table-responsive">

                    <table class="table table-hover align-middle">

                        <thead class="table-light">
                        <tr>
                            <th width="60">#</th>
                            <th>Name</th>
                            <th>Module</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>Default</th>
                            <th>Created By</th>
                            <th>Created</th>
                            <th width="120" class="text-center">Actions</th>
                        </tr>
                        </thead>

                        <tbody>

                        @forelse($workflows as $workflow)

                            <tr>

                                <td>
                                    {{ $loop->iteration }}
                                </td>

                                <td>
                                    <div class="fw-semibold">
                                        {{ $workflow->name }}
                                    </div>
                                </td>

                                <td>
                                    <span class="badge badge-info">
                                        {{ $workflow->module }}
                                    </span>
                                </td>

                                <td>
                                    {{ \Illuminate\Support\Str::limit($workflow->description, 50) ?: 'N/A' }}
                                </td>

                                <td>
                                    @if($workflow->is_active)
                                        <span class="badge badge-success">
                                            Active
                                        </span>
                                    @else
                                        <span class="badge badge-danger">
                                            Inactive
                                        </span>
                                    @endif
                                </td>

                                <td>
                                    @if($workflow->is_default)
                                        <span class="badge badge-primary">
                                            Default
                                        </span>
                                    @else
                                        <span class="text-muted">
                                            No
                                        </span>
                                    @endif
                                </td>

                                <td>
                                    {{ $workflow->creator?->name ?? 'System' }}
                                </td>

                                <td>
                                    {{ $workflow->created_at?->format('d M Y') }}
                                </td>

                                <td class="text-center">

                                    <div class="dropdown">

                                        <button class="btn btn-outline-secondary btn-sm dropdown-toggle"
                                                type="button"
                                                data-toggle="dropdown"
                                                aria-expanded="false">

                                            Actions

                                        </button>

                                        <div class="dropdown-menu dropdown-menu-right">

                                            {{-- VIEW --}}
                                            <a href="{{route('configurations.workflows.workflow_definitions.show', $workflow->id)}}"
                                               class="dropdown-item">

                                                <i class="fas fa-eye mr-2"></i>
                                                View

                                            </a>

                                            {{-- EDIT --}}
                                            <a href="#"
                                               class="dropdown-item"
                                               wire:click.prevent="edit({{ $workflow->id }})">

                                                <i class="fas fa-edit mr-2"></i>
                                                Edit

                                            </a>

                                            <div class="dropdown-divider"></div>

                                            {{-- DELETE --}}
                                            <a href="#"
                                               class="dropdown-item text-danger"
                                               wire:click.prevent="delete({{ $workflow->id }})"
                                               wire:confirm="Are you sure you want to delete this workflow?">

                                                <i class="fas fa-trash mr-2"></i>
                                                Delete

                                            </a>

                                        </div>

                                    </div>

                                </td>

                            </tr>

                        @empty

                            <tr>
                                <td colspan="9" class="text-center text-muted py-5">
                                    No workflow definitions found
                                </td>
                            </tr>

                        @endforelse

                        </tbody>

                    </table>

                </div>

                <div class="mt-3">
                    {{ $workflows->links() }}
                </div>

            </div>

        </div>

    </div>

    {{-- CREATE MODAL --}}
    <div wire:ignore.self
         class="modal fade"
         id="createWorkflowModal"
         tabindex="-1">

        <div class="modal-dialog modal-lg modal-dialog-centered">

            <div class="modal-content border-0 shadow">

                <div class="modal-header bg-primary text-white">

                    <h5 class="modal-title">
                        Create Workflow
                    </h5>

                    <button type="button"
                            class="btn-close btn-close-white"
                            data-dismiss="modal"></button>

                </div>

                <div class="modal-body">

                    <div class="row">

                        <div class="col-md-6 mb-3">

                            <label class="form-label">
                                Workflow Name
                            </label>

                            <input type="text"
                                   wire:model="name"
                                   class="form-control"
                                   placeholder="Enter workflow name">

                            @error('name')
                            <small class="text-danger">{{ $message }}</small>
                            @enderror

                        </div>

                        <div class="col-md-6 mb-3">

                            <label class="form-label">
                                Module
                            </label>

                            <input type="text"
                                   wire:model="module"
                                   class="form-control"
                                   placeholder="e.g. physical_document">

                            @error('module')
                            <small class="text-danger">{{ $message }}</small>
                            @enderror

                        </div>

                        <div class="col-12 mb-3">

                            <label class="form-label">
                                Description
                            </label>

                            <textarea wire:model="description"
                                      class="form-control"
                                      rows="4"
                                      placeholder="Enter workflow description"></textarea>

                            @error('description')
                            <small class="text-danger">{{ $message }}</small>
                            @enderror

                        </div>

                        <div class="col-md-6 mb-3">

                            <div class="custom-control custom-switch">

                                <input type="checkbox"
                                       class="custom-control-input"
                                       id="is_active"
                                       wire:model="is_active">

                                <label class="custom-control-label" for="is_active">
                                    Active Workflow
                                </label>

                            </div>

                        </div>

                        <div class="col-md-6 mb-3">

                            <div class="custom-control custom-switch">

                                <input type="checkbox"
                                       class="custom-control-input"
                                       id="is_default"
                                       wire:model="is_default">

                                <label class="custom-control-label" for="is_default">
                                    Default Workflow
                                </label>

                            </div>

                        </div>

                    </div>

                </div>

                <div class="modal-footer">

                    <button class="btn btn-light"
                            data-dismiss="modal">
                        Cancel
                    </button>

                    <button type="button"
                            class="btn text-white"
                            style="background: {{ config('theme.color_accent') }}"
                            wire:click="save"
                            wire:loading.attr="disabled">

                        <span wire:loading.remove wire:target="save">
                            <i class="fas fa-save mr-1"></i>
                            Save Workflow
                        </span>

                        <span wire:loading wire:target="save">
                            <span class="spinner-border spinner-border-sm"></span>
                        </span>

                    </button>

                </div>

            </div>

        </div>

    </div>

    {{-- EDIT MODAL --}}
    <div wire:ignore.self
         class="modal fade"
         id="editWorkflowModal"
         tabindex="-1">

        <div class="modal-dialog modal-lg modal-dialog-centered">

            <div class="modal-content border-0 shadow">

                <div class="modal-header bg-dark text-white">

                    <h5 class="modal-title">
                        Edit Workflow
                    </h5>

                    <button type="button"
                            class="btn-close btn-close-white"
                            data-dismiss="modal"></button>

                </div>

                <div class="modal-body">

                    <div class="row">

                        <div class="col-md-6 mb-3">

                            <label class="form-label">
                                Workflow Name
                            </label>

                            <input type="text"
                                   wire:model="edit_name"
                                   class="form-control">

                            @error('edit_name')
                            <small class="text-danger">{{ $message }}</small>
                            @enderror

                        </div>

                        <div class="col-md-6 mb-3">

                            <label class="form-label">
                                Module
                            </label>

                            <input type="text"
                                   wire:model="edit_module"
                                   class="form-control">

                            @error('edit_module')
                            <small class="text-danger">{{ $message }}</small>
                            @enderror

                        </div>

                        <div class="col-12 mb-3">

                            <label class="form-label">
                                Description
                            </label>

                            <textarea wire:model="edit_description"
                                      class="form-control"
                                      rows="4"></textarea>

                            @error('edit_description')
                            <small class="text-danger">{{ $message }}</small>
                            @enderror

                        </div>

                        <div class="col-md-6 mb-3">

                            <div class="custom-control custom-switch">

                                <input type="checkbox"
                                       class="custom-control-input"
                                       id="edit_is_active"
                                       wire:model="edit_is_active">

                                <label class="custom-control-label" for="edit_is_active">
                                    Active Workflow
                                </label>

                            </div>

                        </div>

                        <div class="col-md-6 mb-3">

                            <div class="custom-control custom-switch">

                                <input type="checkbox"
                                       class="custom-control-input"
                                       id="edit_is_default"
                                       wire:model="edit_is_default">

                                <label class="custom-control-label" for="edit_is_default">
                                    Default Workflow
                                </label>

                            </div>

                        </div>

                    </div>

                </div>

                <div class="modal-footer">

                    <button class="btn btn-light"
                            data-dismiss="modal">
                        Cancel
                    </button>

                    <button type="button"
                            class="btn text-white"
                            style="background: {{ config('theme.color_accent') }}"
                            wire:click="update"
                            wire:loading.attr="disabled">

                        <span wire:loading.remove wire:target="update">
                            <i class="fas fa-save mr-1"></i>
                            Update Workflow
                        </span>

                        <span wire:loading wire:target="update">
                            <span class="spinner-border spinner-border-sm"></span>
                        </span>

                    </button>

                </div>

            </div>

        </div>

    </div>

    <script>
        window.addEventListener('open-modal', event => {
            $('#' + event.detail.id).modal('show');
        });

        window.addEventListener('close-modal', event => {
            $('#' + event.detail.id).modal('hide');
        });
    </script>

</div>
