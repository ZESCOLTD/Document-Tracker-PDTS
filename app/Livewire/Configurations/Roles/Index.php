<?php

namespace App\Livewire\Configurations\Roles;

use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class Index extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    */

    public $search = '';

    public $perPage = 10;

    /*
    |--------------------------------------------------------------------------
    | Form
    |--------------------------------------------------------------------------
    */

    public $roleId;

    public $name;

    public $selectedPermissions = [];

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    protected function rules()
    {
        return [

            'name' => 'required|max:255|unique:roles,name,' . $this->roleId,

            'selectedPermissions' => 'array',

        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function resetForm()
    {
        $this->reset([
            'roleId',
            'name',
            'selectedPermissions',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Create
    |--------------------------------------------------------------------------
    */

    public function create()
    {
        $this->resetForm();

        $this->dispatch('show-create-modal');
    }
    public function selectAllPermissions()
    {
        $this->selectedPermissions = Permission::pluck('name')->toArray();
    }

    public function clearPermissions()
    {
        $this->selectedPermissions = [];
    }
    public function save()
    {
        $this->validate();

        $role = Role::create([

            'name' => $this->name,

            'guard_name' => 'web',

        ]);

        $role->syncPermissions($this->selectedPermissions);

        $this->dispatch('close-modal');

        session()->flash(
            'success',
            'Role created successfully.'
        );

        $this->resetForm();
    }

    /*
    |--------------------------------------------------------------------------
    | Edit
    |--------------------------------------------------------------------------
    */

    public function edit(Role $role)
    {
        $this->resetForm();

        $this->roleId = $role->id;

        $this->name = $role->name;

        $this->selectedPermissions = $role
            ->permissions
            ->pluck('name')
            ->toArray();

        $this->dispatch('show-edit-modal');
    }

    public function update()
    {
        $this->validate();

        $role = Role::findOrFail($this->roleId);

        $role->update([

            'name' => $this->name,

        ]);

        $role->syncPermissions($this->selectedPermissions);

        $this->dispatch('close-modal');

        session()->flash(
            'success',
            'Role updated successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Delete
    |--------------------------------------------------------------------------
    */

    public function delete($id)
    {
        Role::findOrFail($id)->delete();

        session()->flash(
            'success',
            'Role deleted successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Render
    |--------------------------------------------------------------------------
    */

    public function render()
    {
        return view('livewire.configurations.roles.index', [

            'roles' => Role::withCount('users')
                ->when($this->search, function ($query) {

                    $query->where(
                        'name',
                        'like',
                        '%' . $this->search . '%'
                    );

                })
                ->latest()
                ->paginate($this->perPage),

            'permissions' => Permission::all(),

            'permissionGroups' => Permission::all()
                ->groupBy(function ($permission) {

                    return explode('.', $permission->name)[0];

                }),

        ])->layout('layouts.master');
    }
}
