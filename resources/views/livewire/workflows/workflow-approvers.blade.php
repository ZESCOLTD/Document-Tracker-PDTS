<div>
    <h3>Assign Users to Workflow Roles</h3>

    @if(session()->has('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    {{-- Select Initiator --}}
    <div class="mb-3">
        <label for="initiator">Select Initiator</label>
        <select id="initiator" class="form-control" wire:change="loadInitiatorWorkflow($event.target.value)">
            <option value="">Select Initiator</option>
            @foreach($initiators as $initiator)
                <option value="{{ $initiator->id }}" @if($selectedInitiator == $initiator->id) selected @endif>
                    {{ $initiator->name }} ({{ $initiator->email }})
                </option>
            @endforeach
        </select>

        <div wire:loading wire:target="loadInitiatorWorkflow" class="text-info mt-1">
            Loading workflow...
        </div>
    </div>

    {{-- Only show workflow after initiator is selected --}}
    @if($selectedInitiator)
        {{-- Workflow Definition --}}
        <div class="mb-3">
            <label for="workflow-definition">Workflow Definition</label>
            <select id="workflow-definition" class="form-control" wire:change="loadWorkflowSteps($event.target.value)">
                <option value="">Select Workflow</option>
                @foreach($workflowDefinitions as $definition)
                    <option value="{{ $definition->id }}" @if($selectedWorkflowDefinition == $definition->id) selected @endif>
                        {{ $definition->name }}
                    </option>
                @endforeach
            </select>
            <div wire:loading wire:target="loadWorkflowSteps" class="text-info mt-1">
                Loading steps...
            </div>
        </div>

        {{-- Workflow Steps --}}
        @if($workflowSteps && $workflowSteps->count() > 0)
            <div class="mb-3">
                <label for="workflow-step">Workflow Step</label>
                <select id="workflow-step" class="form-control" wire:change="loadStepUsers($event.target.value)">
                    <option value="">Select Step</option>
                    @foreach($workflowSteps as $step)
                        <option value="{{ $step->id }}" @if($selectedStep == $step->id) selected @endif>
                            {{ $step->step_name }}
                        </option>
                    @endforeach
                </select>
                <div wire:loading wire:target="loadStepUsers" class="text-info mt-1">
                    Loading assigned users...
                </div>
            </div>
        @endif

        {{-- Assign Users --}}
        @if($selectedStep)
            <div class="mb-3">
                <label for="users">Assign Users</label>
                <select id="users" wire:model="selectedUsers" multiple class="form-control">
                    @foreach($users as $user)
                        <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                    @endforeach
                </select>
                <small class="text-muted">Hold Ctrl (Windows) or Cmd (Mac) to select multiple users</small>
            </div>
            <button wire:click="save" class="btn btn-primary">Save Approvers</button>
            <div wire:loading wire:target="save" class="text-info mt-1">
                Saving assignments...
            </div>
        @endif

        {{-- Assigned Users Per Step --}}
        @if($assignedUsersPerStep)
            <div class="mt-4">
                <h5>Current Workflow Assignments for {{ optional($initiators->find($selectedInitiator))->name }}</h5>
                @foreach($assignedUsersPerStep as $stepId => $data)
                    <div class="card mb-2">
                        <div class="card-header">
                            Step: {{ $data['step_name'] }}
                        </div>
                        <div class="card-body">
                            @if($data['users']->count() > 0)
                                <ul class="list-group">
                                    @foreach($data['users'] as $approver)
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            {{ $approver->user->name }} ({{ $approver->user->email }})
                                            @if($approver->is_primary)
                                                <span class="badge bg-success ms-2">Primary</span>
                                            @endif
                                            <button type="button" class="btn btn-sm btn-danger"
                                                    wire:click="removeUserAssignment({{ $stepId }}, {{ $approver->user_id }})">
                                                Remove
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                <p><em>No users assigned yet for this step.</em></p>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</div>
