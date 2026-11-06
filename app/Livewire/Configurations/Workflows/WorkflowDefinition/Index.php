<?php

namespace App\Livewire\Configurations\Workflows\WorkflowDefinition;

use App\Models\WorkflowDefinition;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public $search = '';

    public $workflow_id;

    public $name;
    public $module;
    public $description;
    public $is_active = true;
    public $is_default = false;

    public $edit_name;
    public $edit_module;
    public $edit_description;
    public $edit_is_active = true;
    public $edit_is_default = false;

    protected function rules()
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'module' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
        ];
    }

    public function render()
    {
        $workflows = WorkflowDefinition::query()
            ->with('creator')
            ->when($this->search, function ($query) {
                $query->where('name', 'like', '%' . $this->search . '%')
                    ->orWhere('module', 'like', '%' . $this->search . '%');
            })
            ->latest()
            ->paginate(10);

        return view('livewire.configurations.workflows.workflow-definitions.index', [
            'workflows' => $workflows
        ])->layout('layouts.master');
    }

    public function save()
    {
        $this->validate();

        WorkflowDefinition::create([
            'name' => $this->name,
            'module' => $this->module,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'is_default' => $this->is_default,
            'created_by' => Auth::id(),
        ]);

        $this->resetForm();

        $this->dispatch('close-modal', id: 'createWorkflowModal');

        session()->flash('success', 'Workflow created successfully.');
    }

    public function edit($id)
    {
        $workflow = WorkflowDefinition::findOrFail($id);

        $this->workflow_id = $workflow->id;

        $this->edit_name = $workflow->name;
        $this->edit_module = $workflow->module;
        $this->edit_description = $workflow->description;
        $this->edit_is_active = $workflow->is_active;
        $this->edit_is_default = $workflow->is_default;

        $this->dispatch('open-modal', id: 'editWorkflowModal');
    }

    public function update()
    {
        $this->validate([
            'edit_name' => ['required', 'string', 'max:255'],
            'edit_module' => ['required', 'string', 'max:255'],
            'edit_description' => ['nullable', 'string'],
            'edit_is_active' => ['boolean'],
            'edit_is_default' => ['boolean'],
        ]);

        $workflow = WorkflowDefinition::findOrFail($this->workflow_id);

        $workflow->update([
            'name' => $this->edit_name,
            'module' => $this->edit_module,
            'description' => $this->edit_description,
            'is_active' => $this->edit_is_active,
            'is_default' => $this->edit_is_default,
        ]);

        $this->dispatch('close-modal', id: 'editWorkflowModal');

        session()->flash('success', 'Workflow updated successfully.');
    }

    public function delete($id)
    {
        WorkflowDefinition::findOrFail($id)->delete();

        session()->flash('success', 'Workflow deleted successfully.');
    }

    public function view($id)
    {
        return redirect()->route('configurations.workflows.workflow_definitions.show', $id);
    }

    public function resetForm()
    {
        $this->reset([
            'name',
            'module',
            'description',
            'is_active',
            'is_default',
        ]);

        $this->is_active = true;
        $this->is_default = false;
    }
}
