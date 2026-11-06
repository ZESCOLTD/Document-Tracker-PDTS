<?php

namespace App\Livewire\Workflows;

use Livewire\Component;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepApprover;

class WorkflowApprovers extends Component
{
    public $initiators = [];
    public $selectedInitiator;

    public $workflowDefinitions = [];
    public $workflowSteps = [];
    public $users = [];

    public $selectedWorkflowDefinition;
    public $selectedStep;
    public $selectedUsers = [];

    public $assignedUsersPerStep = [];

    public function mount()
    {
        $this->initiators = User::all(); // Initiators
        $this->users = User::all();      // Possible approvers
        $this->workflowDefinitions = WorkflowDefinition::all();
    }

    /** Load the workflow for a selected initiator */
    public function loadInitiatorWorkflow($initiatorId)
    {
        $this->selectedInitiator = $initiatorId;
        $this->selectedWorkflowDefinition = null;
        $this->workflowSteps = collect();
        $this->selectedStep = null;
        $this->selectedUsers = [];
        $this->assignedUsersPerStep = [];

        if (!$initiatorId) return;

        // Optionally get current workflow definition assigned to initiator
        $currentStep = WorkflowStepApprover::where('initiator_user_id', $initiatorId)->first();
        if ($currentStep) {
            $this->selectedWorkflowDefinition = $currentStep->workflow_definition_id;
            $this->workflowSteps = WorkflowStep::where('workflow_definition_id', $this->selectedWorkflowDefinition)->get();
            $this->loadAssignedUsers($this->selectedWorkflowDefinition);
        }
    }

    /** Load steps for selected workflow definition */
    public function loadWorkflowSteps($definitionId)
    {
        $this->selectedWorkflowDefinition = $definitionId;
        $this->workflowSteps = $definitionId ? WorkflowStep::where('workflow_definition_id', $definitionId)->get() : collect();
        $this->selectedStep = null;
        $this->selectedUsers = [];
        $this->assignedUsersPerStep = [];

        $this->loadAssignedUsers($definitionId);
    }

    /** Load users already assigned for a workflow step */
    public function loadStepUsers($stepId)
    {
        $this->selectedStep = $stepId;
        $this->selectedUsers = WorkflowStepApprover::where('workflow_step_id', $stepId)
            ->where('initiator_user_id', $this->selectedInitiator)
            ->where('is_active', true)
            ->pluck('user_id')
            ->toArray();
    }

    /** Load all assigned users for steps of a workflow */
    public function loadAssignedUsers($workflowDefinitionId)
    {
        if (!$workflowDefinitionId || !$this->selectedInitiator) {
            $this->assignedUsersPerStep = [];
            return;
        }

        $steps = WorkflowStep::where('workflow_definition_id', $workflowDefinitionId)->get();
        $this->assignedUsersPerStep = [];

        foreach ($steps as $step) {
            $approvers = WorkflowStepApprover::where('workflow_step_id', $step->id)
                ->where('initiator_user_id', $this->selectedInitiator)
                ->where('is_active', true)
                ->with('user')
                ->get();

            $this->assignedUsersPerStep[$step->id] = [
                'step_name' => $step->step_name,
                'users' => $approvers
            ];
        }
    }

    /** Save assigned users for a workflow step */
    public function save()
    {
        if (!$this->selectedStep || !$this->selectedInitiator) {
            $this->addError('selectedStep', 'Please select a step and initiator.');
            return;
        }

        // Deduplicate selected users to prevent duplicate records
        $uniqueUserIds = array_unique(array_filter($this->selectedUsers));

        // Remove previous assignments for this step & initiator
        WorkflowStepApprover::where('workflow_step_id', $this->selectedStep)
            ->where('initiator_user_id', $this->selectedInitiator)
            ->delete();

        // Add new assignments (deduplicated)
        foreach ($uniqueUserIds as $userId) {
            WorkflowStepApprover::create([
                'initiator_user_id' => $this->selectedInitiator,
                'workflow_definition_id' => $this->selectedWorkflowDefinition,
                'workflow_step_id' => $this->selectedStep,
                'user_id' => $userId,
                'is_primary' => true,
                'is_active' => true,
            ]);
        }

        session()->flash('success', 'Approvers updated successfully.');
        $this->loadAssignedUsers($this->selectedWorkflowDefinition);
    }

    /** Remove a single user from a workflow step assignment */
    public function removeUserAssignment($stepId, $userId)
    {
        WorkflowStepApprover::where('workflow_step_id', $stepId)
            ->where('initiator_user_id', $this->selectedInitiator)
            ->where('user_id', $userId)
            ->delete();

        $this->loadAssignedUsers($this->selectedWorkflowDefinition);
    }

    public function render()
    {
        return view('livewire.workflows.workflow-approvers')
            ->layout('layouts.master', ['title' => 'Workflow Approvers']);
    }
}
