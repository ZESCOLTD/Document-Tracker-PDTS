<div>

    <x-slot name="title">
        Workflow Details
    </x-slot>

    <x-slot name="pageHeader">
        Workflow Details
    </x-slot>

    <div class="container-fluid">

        {{-- PAGE HEADER --}}
        <div class="card border-0 shadow-sm mb-4">

            <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-3">

                <div>

                    <div class="d-flex align-items-center mb-2">

                        <h3 class="mb-0 fw-bold">
                            {{ $workflow->name }}
                        </h3>

                        @if($workflow->is_active)
                            <span class="badge badge-success ml-3">
                                Active
                            </span>
                        @else
                            <span class="badge badge-danger ml-3">
                                Inactive
                            </span>
                        @endif

                    </div>

                    <div class="text-muted">

                        Module:
                        <span class="fw-semibold">
                            {{ $workflow->module }}
                        </span>

                    </div>

                </div>

                <div class="d-flex align-items-center gap-2">

                    <a href="{{ route('configurations.workflows.workflow_definitions.index') }}"
                       class="btn btn-light border">

                        <i class="fas fa-arrow-left mr-1"></i>
                        Back

                    </a>

                    <button class="btn btn-primary"
                            data-toggle="modal"
                            data-target="#addStepModal">

                        <i class="fas fa-plus-circle mr-1"></i>
                        Add Step

                    </button>

                </div>

            </div>

        </div>

        {{-- WORKFLOW DETAILS --}}
        <div class="row">

            {{-- LEFT --}}
            <div class="col-lg-4">

                <div class="card border-0 shadow-sm mb-4">

                    <div class="card-header bg-light">

                        <h5 class="mb-0">
                            Workflow Information
                        </h5>

                    </div>

                    <div class="card-body">

                        <div class="mb-4">

                            <small class="text-muted d-block">
                                Workflow Name
                            </small>

                            <div class="fw-semibold">
                                {{ $workflow->name }}
                            </div>

                        </div>

                        <div class="mb-4">

                            <small class="text-muted d-block">
                                Module
                            </small>

                            <div>
                                <span class="badge badge-info">
                                    {{ $workflow->module }}
                                </span>
                            </div>

                        </div>

                        <div class="mb-4">

                            <small class="text-muted d-block">
                                Description
                            </small>

                            <div>
                                {{ $workflow->description ?: 'No description provided.' }}
                            </div>

                        </div>

                        <div class="mb-4">

                            <small class="text-muted d-block">
                                Status
                            </small>

                            @if($workflow->is_active)
                                <span class="badge badge-success">
                                    Active
                                </span>
                            @else
                                <span class="badge badge-danger">
                                    Inactive
                                </span>
                            @endif

                        </div>

                        <div class="mb-4">

                            <small class="text-muted d-block">
                                Default Workflow
                            </small>

                            @if($workflow->is_default)
                                <span class="badge badge-primary">
                                    Yes
                                </span>
                            @else
                                <span class="text-muted">
                                    No
                                </span>
                            @endif

                        </div>

                        <div class="mb-4">

                            <small class="text-muted d-block">
                                Created By
                            </small>

                            <div>
                                {{ $workflow->creator?->name ?? 'System' }}
                            </div>

                        </div>

                        <div>

                            <small class="text-muted d-block">
                                Created Date
                            </small>

                            <div>
                                {{ $workflow->created_at?->format('d M Y h:i A') }}
                            </div>

                        </div>

                    </div>

                </div>

            </div>

            {{-- RIGHT --}}
            <div class="col-lg-8">

                <div class="card border-0 shadow-sm">

                    <div class="card-header bg-light d-flex justify-content-between align-items-center">

                        <div>

                            <h5 class="mb-0">
                                Workflow Steps
                            </h5>

                            <small class="text-muted">
                                Approval flow configuration
                            </small>

                        </div>

                        <span class="badge badge-dark px-3 py-2">
                            {{ $workflow->steps->count() }} Steps
                        </span>

                    </div>

                    <div class="card-body">

                        @forelse($workflow->steps as $step)

                            <div class="card border mb-3">

                                <div class="card-body">

                                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">

                                        <div class="flex-grow-1">

                                            <div class="d-flex align-items-center mb-2">

                                                <div class="badge badge-primary mr-2 px-3 py-2">
                                                    Step {{ $step->step_order }}
                                                </div>

                                                <h5 class="mb-0">
                                                    {{ $step->step_name }}
                                                </h5>

                                            </div>

                                            <div class="row mt-3">

                                                <div class="col-md-6 mb-3">

                                                    <small class="text-muted d-block">
                                                        Step Code
                                                    </small>

                                                    <div>
                                                        {{ $step->step_code ?: 'N/A' }}
                                                    </div>

                                                </div>

                                                <div class="col-md-6 mb-3">

                                                    <small class="text-muted d-block">
                                                        Approver Type
                                                    </small>

                                                    <div>
                                                        <span class="badge badge-info">
                                                            {{ ucfirst(str_replace('_', ' ', $step->approver_type)) }}
                                                        </span>
                                                    </div>

                                                </div>

                                                <div class="col-md-6 mb-3">

                                                    <small class="text-muted d-block">
                                                        Approver Value
                                                    </small>

                                                    <div>
                                                        {{ $step->approver_value ?: 'N/A' }}
                                                    </div>

                                                </div>

                                                <div class="col-md-6 mb-3">

                                                    <small class="text-muted d-block">
                                                        Status
                                                    </small>

                                                    @if($step->is_active)
                                                        <span class="badge badge-success">
                                                            Active
                                                        </span>
                                                    @else
                                                        <span class="badge badge-danger">
                                                            Inactive
                                                        </span>
                                                    @endif

                                                </div>

                                            </div>

                                            {{-- PERMISSIONS --}}
                                            <div class="border-top pt-3 mt-2">

                                                <h6 class="mb-3">
                                                    Step Permissions
                                                </h6>

                                                <div class="d-flex flex-wrap gap-2">

                                                    @if($step->allow_approve)
                                                        <span class="badge badge-success">
                                                            Approve Allowed
                                                        </span>
                                                    @endif

                                                    @if($step->allow_reject)
                                                        <span class="badge badge-danger">
                                                            Reject Allowed
                                                        </span>
                                                    @endif

                                                    @if($step->allow_revert)
                                                        <span class="badge badge-warning">
                                                            Revert Allowed
                                                        </span>
                                                    @endif

                                                    @if($step->comment_required)
                                                        <span class="badge badge-dark">
                                                            Comment Required
                                                        </span>
                                                    @endif

                                                </div>

                                            </div>

                                        </div>

                                        {{-- ACTIONS --}}
                                        <div>

                                            <div class="dropdown">

                                                <button class="btn btn-outline-secondary btn-sm dropdown-toggle"
                                                        type="button"
                                                        data-toggle="dropdown">

                                                    Actions

                                                </button>

                                                <div class="dropdown-menu dropdown-menu-right">

                                                    {{-- EDIT --}}
                                                    <a href="#"
                                                       class="dropdown-item"
                                                       wire:click.prevent="editStep({{ $step->id }})">

                                                        <i class="fas fa-edit mr-2"></i>
                                                        Edit Step

                                                    </a>

                                                    {{-- DELETE --}}
                                                    <a href="#"
                                                       class="dropdown-item text-danger"
                                                       wire:click.prevent="confirmDeleteStep({{ $step->id }})">

                                                        <i class="fas fa-trash mr-2"></i>
                                                        Delete Step

                                                    </a>

                                                </div>
                                            </div>

                                        </div>

                                    </div>

                                </div>

                            </div>

                        @empty

                            <div class="text-center py-5">

                                <div class="mb-3">
                                    <i class="fas fa-project-diagram fa-3x text-muted"></i>
                                </div>

                                <h5 class="text-muted">
                                    No workflow steps configured
                                </h5>

                                <p class="text-muted">
                                    Start by adding workflow approval steps.
                                </p>

                                <button class="btn btn-primary"
                                        data-toggle="modal"
                                        data-target="#addStepModal">

                                    <i class="fas fa-plus-circle mr-1"></i>
                                    Add First Step

                                </button>

                            </div>

                        @endforelse

                    </div>

                </div>

            </div>

        </div>

    </div>

{{--    /************************************MODALS ***********/--}}

    {{-- ADD STEP MODAL --}}
    <div wire:ignore.self
         class="modal fade"
         id="addStepModal"
         tabindex="-1">

        <div class="modal-dialog modal-xl modal-dialog-centered">

            <div class="modal-content border-0 shadow">

                <div class="modal-header bg-primary text-white">

                    <h5 class="modal-title">
                        Add Workflow Step
                    </h5>

                    <button type="button"
                            class="btn-close btn-close-white"
                            data-dismiss="modal"></button>

                </div>

                <div class="modal-body">

                    <div class="row">

                        {{-- STEP ORDER --}}
                        <div class="col-md-4 mb-3">

                            <label class="form-label">
                                Step Order
                            </label>

                            <input type="number"
                                   class="form-control"
                                   wire:model="step_order"
                                   placeholder="1">

                            @error('step_order')
                            <small class="text-danger">
                                {{ $message }}
                            </small>
                            @enderror

                        </div>

                        {{-- STEP NAME --}}
                        <div class="col-md-4 mb-3">

                            <label class="form-label">
                                Step Name
                            </label>

                            <input type="text"
                                   class="form-control"
                                   wire:model="step_name"
                                   placeholder="e.g Line Manager">

                            @error('step_name')
                            <small class="text-danger">
                                {{ $message }}
                            </small>
                            @enderror

                        </div>

                        {{-- STEP CODE --}}
                        <div class="col-md-4 mb-3">

                            <label class="form-label">
                                Step Code
                            </label>

                            <input type="text"
                                   class="form-control"
                                   wire:model="step_code"
                                   placeholder="e.g line_manager">

                            @error('step_code')
                            <small class="text-danger">
                                {{ $message }}
                            </small>
                            @enderror

                        </div>

                        {{-- APPROVER TYPE --}}
                        <div class="col-md-6 mb-3">

                            <label class="form-label">
                                Approver Type
                            </label>

                            <select class="form-control"
                                    wire:model.live="approver_type">

                                <option value="">
                                    Select Approver Type
                                </option>

                                <option value="user">
                                    User
                                </option>

                                <option value="role">
                                    Role
                                </option>

                                <option value="job_title">
                                    Job Title
                                </option>

                                <option value="department_head">
                                    Department Head
                                </option>

                                <option value="reporting_manager">
                                    Reporting Manager
                                </option>

                            </select>

                            @error('approver_type')
                            <small class="text-danger">
                                {{ $message }}
                            </small>
                            @enderror

                        </div>

                        {{-- APPROVER VALUE --}}
                        <div class="col-md-6 mb-3">

                            <label class="form-label">
                                Approver Value
                            </label>

                            <input type="text"
                                   class="form-control"
                                   wire:model="approver_value"
                                   placeholder="Enter approver value">

                            @error('approver_value')
                            <small class="text-danger">
                                {{ $message }}
                            </small>
                            @enderror

                        </div>

                        {{-- OPTIONS --}}
                        <div class="col-12">

                            <div class="card border bg-light">

                                <div class="card-body">

                                    <h6 class="mb-4">
                                        Step Permissions & Settings
                                    </h6>

                                    <div class="row">

                                        <div class="col-md-3 mb-3">

                                            <div class="custom-control custom-switch">

                                                <input type="checkbox"
                                                       class="custom-control-input"
                                                       id="allow_approve"
                                                       wire:model="allow_approve">

                                                <label class="custom-control-label"
                                                       for="allow_approve">

                                                    Allow Approve

                                                </label>

                                            </div>

                                        </div>

                                        <div class="col-md-3 mb-3">

                                            <div class="custom-control custom-switch">

                                                <input type="checkbox"
                                                       class="custom-control-input"
                                                       id="allow_reject"
                                                       wire:model="allow_reject">

                                                <label class="custom-control-label"
                                                       for="allow_reject">

                                                    Allow Reject

                                                </label>

                                            </div>

                                        </div>

                                        <div class="col-md-3 mb-3">

                                            <div class="custom-control custom-switch">

                                                <input type="checkbox"
                                                       class="custom-control-input"
                                                       id="allow_revert"
                                                       wire:model="allow_revert">

                                                <label class="custom-control-label"
                                                       for="allow_revert">

                                                    Allow Revert

                                                </label>

                                            </div>

                                        </div>

                                        <div class="col-md-3 mb-3">

                                            <div class="custom-control custom-switch">

                                                <input type="checkbox"
                                                       class="custom-control-input"
                                                       id="comment_required"
                                                       wire:model="comment_required">

                                                <label class="custom-control-label"
                                                       for="comment_required">

                                                    Comment Required

                                                </label>

                                            </div>

                                        </div>

                                        <div class="col-md-3 mb-3">

                                            <div class="custom-control custom-switch">

                                                <input type="checkbox"
                                                       class="custom-control-input"
                                                       id="step_is_active"
                                                       wire:model="step_is_active">

                                                <label class="custom-control-label"
                                                       for="step_is_active">

                                                    Active Step

                                                </label>

                                            </div>

                                        </div>

                                    </div>

                                </div>

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
                            wire:click="saveStep"
                            wire:loading.attr="disabled">

                    <span wire:loading.remove wire:target="saveStep">

                        <i class="fas fa-save mr-1"></i>
                        Save Step

                    </span>

                        <span wire:loading wire:target="saveStep">

                        <span class="spinner-border spinner-border-sm"></span>

                    </span>

                    </button>

                </div>

            </div>

        </div>

    </div>

    {{-- EDIT STEP MODAL --}}
    <div
         class="modal fade"
         id="editStepModal"
         tabindex="-1">

        <div class="modal-dialog modal-xl modal-dialog-centered">

            <div class="modal-content border-0 shadow">

                <div class="modal-header bg-dark text-white">

                    <h5 class="modal-title">
                        Edit Workflow Step
                    </h5>

                    <button type="button"
                            class="btn-close btn-close-white"
                            data-dismiss="modal"></button>

                </div>

                <div class="modal-body">

                    <div class="row">

                        {{-- STEP ORDER --}}
                        <div class="col-md-4 mb-3">

                            <label class="form-label">
                                Step Order
                            </label>

                            <input type="number"
                                   class="form-control"
                                   wire:model="edit_step_order">

                        </div>

                        {{-- STEP NAME --}}
                        <div class="col-md-4 mb-3">

                            <label class="form-label">
                                Step Name
                            </label>

                            <input type="text"
                                   class="form-control"
                                   wire:model="edit_step_name">

                        </div>

                        {{-- STEP CODE --}}
                        <div class="col-md-4 mb-3">

                            <label class="form-label">
                                Step Code
                            </label>

                            <input type="text"
                                   class="form-control"
                                   wire:model="edit_step_code">

                        </div>

                        {{-- APPROVER TYPE --}}
                        <div class="col-md-6 mb-3">

                            <label class="form-label">
                                Approver Type
                            </label>

                            <select class="form-control"
                                    wire:model="edit_approver_type">

                                <option value="user">User</option>
                                <option value="role">Role</option>
                                <option value="job_title">Job Title</option>
                                <option value="department_head">Department Head</option>
                                <option value="reporting_manager">Reporting Manager</option>

                            </select>

                        </div>

                        {{-- APPROVER VALUE --}}
                        <div class="col-md-6 mb-3">

                            <label class="form-label">
                                Approver Value
                            </label>

                            <input type="text"
                                   class="form-control"
                                   wire:model="edit_approver_value">

                        </div>

                        {{-- OPTIONS --}}
                        <div class="col-12">

                            <div class="card border bg-light">

                                <div class="card-body">

                                    <h6 class="mb-4">
                                        Step Permissions & Settings
                                    </h6>

                                    <div class="row">

                                        <div class="col-md-3 mb-3">

                                            <div class="custom-control custom-switch">

                                                <input type="checkbox"
                                                       class="custom-control-input"
                                                       id="edit_allow_approve"
                                                       wire:model="edit_allow_approve">

                                                <label class="custom-control-label"
                                                       for="edit_allow_approve">

                                                    Allow Approve

                                                </label>

                                            </div>

                                        </div>

                                        <div class="col-md-3 mb-3">

                                            <div class="custom-control custom-switch">

                                                <input type="checkbox"
                                                       class="custom-control-input"
                                                       id="edit_allow_reject"
                                                       wire:model="edit_allow_reject">

                                                <label class="custom-control-label"
                                                       for="edit_allow_reject">

                                                    Allow Reject

                                                </label>

                                            </div>

                                        </div>

                                        <div class="col-md-3 mb-3">

                                            <div class="custom-control custom-switch">

                                                <input type="checkbox"
                                                       class="custom-control-input"
                                                       id="edit_allow_revert"
                                                       wire:model="edit_allow_revert">

                                                <label class="custom-control-label"
                                                       for="edit_allow_revert">

                                                    Allow Revert

                                                </label>

                                            </div>

                                        </div>

                                        <div class="col-md-3 mb-3">

                                            <div class="custom-control custom-switch">

                                                <input type="checkbox"
                                                       class="custom-control-input"
                                                       id="edit_comment_required"
                                                       wire:model="edit_comment_required">

                                                <label class="custom-control-label"
                                                       for="edit_comment_required">

                                                    Comment Required

                                                </label>

                                            </div>

                                        </div>

                                        <div class="col-md-3 mb-3">

                                            <div class="custom-control custom-switch">

                                                <input type="checkbox"
                                                       class="custom-control-input"
                                                       id="edit_step_is_active"
                                                       wire:model="edit_step_is_active">

                                                <label class="custom-control-label"
                                                       for="edit_step_is_active">

                                                    Active Step

                                                </label>

                                            </div>

                                        </div>

                                    </div>

                                </div>

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
                            wire:click="updateStep"
                            wire:loading.attr="disabled">

                    <span wire:loading.remove wire:target="updateStep">

                        <i class="fas fa-save mr-1"></i>
                        Update Step

                    </span>

                        <span wire:loading wire:target="updateStep">

                        <span class="spinner-border spinner-border-sm"></span>

                    </span>

                    </button>

                </div>

            </div>

        </div>

    </div>
    {{-- DELETE STEP MODAL --}}
    <div
         class="modal fade"
         id="deleteStepModal"
         tabindex="-1">

        <div class="modal-dialog modal-dialog-centered">

            <div class="modal-content border-0 shadow">

                <div class="modal-header bg-danger text-white">

                    <h5 class="modal-title">
                        Delete Workflow Step
                    </h5>

                    <button type="button"
                            class="btn-close btn-close-white"
                            data-dismiss="modal"></button>

                </div>

                <div class="modal-body text-center py-4">

                    <div class="mb-4">

                        <i class="fas fa-trash-alt fa-3x text-danger"></i>

                    </div>

                    <h5 class="mb-3">
                        Confirm Delete
                    </h5>

                    <p class="text-muted">

                        Are you sure you want to delete this workflow step?

                    </p>

                    @if($delete_step_name)

                        <div class="alert alert-light border">

                            <strong>
                                {{ $delete_step_name }}
                            </strong>

                        </div>

                    @endif

                </div>

                <div class="modal-footer">

                    <button type="button"
                            class="btn btn-light"
                            data-dismiss="modal">

                        Cancel

                    </button>

                    <button type="button"
                            class="btn btn-danger"
                            wire:click="deleteStep"
                            wire:loading.attr="disabled">

                    <span wire:loading.remove wire:target="deleteStep">

                        <i class="fas fa-trash mr-1"></i>
                        Delete Step

                    </span>

                        <span wire:loading wire:target="deleteStep">

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
