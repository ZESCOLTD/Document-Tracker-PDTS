<?php

namespace App\Livewire\Configurations\Workflows\WorkflowDefinition;


use App\Models\WorkflowDefinition;
use App\Models\WorkflowStep;
use Livewire\Component;

class Show extends Component
{
    public WorkflowDefinition $workflow;
    public $step_order;
    public $step_name;
    public $step_code;

    public $approver_type;
    public $approver_value;

    public $allow_approve = true;
    public $allow_reject = true;
    public $allow_revert = true;
    public $comment_required = true;
    public $step_is_active = true;
    public $delete_step_id;
    public $delete_step_name;

    public $step_id;

    public $edit_step_order;
    public $edit_step_name;
    public $edit_step_code;

    public $edit_approver_type;
    public $edit_approver_value;

    public $edit_allow_approve = true;
    public $edit_allow_reject = true;
    public $edit_allow_revert = true;
    public $edit_comment_required = true;
    public $edit_step_is_active = true;

    public function mount($id)
    {
        $this->workflow = WorkflowDefinition::with([
            'creator',
            'steps' => function ($query) {
                $query->orderBy('step_order');
            }
        ])->findOrFail($id);
    }

    public function saveStep()
    {
        $this->validate([
            'step_order' => ['required', 'integer'],
            'step_name' => ['required', 'string'],
            'approver_type' => ['required', 'string'],
        ]);

        WorkflowStep::create([
            'workflow_definition_id' => $this->workflow->id,

            'step_order' => $this->step_order,
            'step_name' => $this->step_name,
            'step_code' => $this->step_code,

            'approver_type' => $this->approver_type,
            'approver_value' => $this->approver_value,

            'allow_approve' => $this->allow_approve,
            'allow_reject' => $this->allow_reject,
            'allow_revert' => $this->allow_revert,
            'comment_required' => $this->comment_required,

            'is_active' => $this->step_is_active,
        ]);

        $this->reset([
            'step_order',
            'step_name',
            'step_code',
            'approver_type',
            'approver_value',
        ]);

        $this->allow_approve = true;
        $this->allow_reject = true;
        $this->allow_revert = true;
        $this->comment_required = true;
        $this->step_is_active = true;

        $this->workflow->refresh();

        $this->dispatch('close-modal', id: 'addStepModal');

        session()->flash('success', 'Workflow step created successfully.');
    }
    public function editStep($id)
    {
        $step = \App\Models\WorkflowStep::findOrFail($id);

        $this->step_id = $step->id;

        $this->edit_step_order = $step->step_order;
        $this->edit_step_name = $step->step_name;
        $this->edit_step_code = $step->step_code;
        $this->edit_approver_type = $step->approver_type;
        $this->edit_approver_value = $step->approver_value;

        $this->edit_allow_approve = $step->allow_approve;
        $this->edit_allow_reject = $step->allow_reject;
        $this->edit_allow_revert = $step->allow_revert;
        $this->edit_comment_required = $step->comment_required;
        $this->edit_step_is_active = $step->is_active;

        $this->dispatch('open-modal', id: 'editStepModal');
    }
    public function confirmDeleteStep($id)
    {
        $step = WorkflowStep::findOrFail($id);

        $this->delete_step_id = $step->id;
        $this->delete_step_name = $step->step_name;

        $this->dispatch('open-modal', id: 'deleteStepModal');
    }

    public function deleteStep()
    {
        WorkflowStep::findOrFail($this->delete_step_id)->delete();

        $this->dispatch('close-modal', id: 'deleteStepModal');

        $this->workflow->refresh();

        session()->flash('success', 'Workflow step deleted successfully.');
    }

    public function updateStep()
    {
        $this->validate([
            'edit_step_order' => ['required', 'integer'],
            'edit_step_name' => ['required', 'string'],
            'edit_approver_type' => ['required', 'string'],
        ]);

        $step = \App\Models\WorkflowStep::findOrFail($this->step_id);

        $step->update([
            'step_order' => $this->edit_step_order,
            'step_name' => $this->edit_step_name,
            'step_code' => $this->edit_step_code,
            'approver_type' => $this->edit_approver_type,
            'approver_value' => $this->edit_approver_value,
            'allow_approve' => $this->edit_allow_approve,
            'allow_reject' => $this->edit_allow_reject,
            'allow_revert' => $this->edit_allow_revert,
            'comment_required' => $this->edit_comment_required,
            'is_active' => $this->edit_step_is_active,
        ]);

        $this->dispatch('close-modal', id: 'editStepModal');

        session()->flash('success', 'Workflow step updated successfully.');

        $this->workflow->refresh();
    }

    public function render()
    {
        return view('livewire.configurations.workflows.workflow-definitions.show')->layout('layouts.master');
    }
}
