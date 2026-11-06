<?php

namespace App\Livewire\Users;

use App\Models\User;
use App\Models\Role;
use Livewire\Component;
use Illuminate\Support\Facades\Hash;

class UserCreate extends Component
{
    public $name, $email, $password, $role_id, $mobile_no, $man_no;

    protected function rules()
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:users,email',
            'password' => 'required|min:6',
            'role_id' => 'required',
            'mobile_no' => 'nullable',
            'man_no' => 'nullable|unique:users,man_no',
        ];
    }

    public function save()
    {
        $this->validate();

        User::create([
            'name' => $this->name,
            'email' => $this->email,
            'mobile_no' => $this->mobile_no,
            'man_no' => $this->man_no,
            'password' => Hash::make($this->password),
            'role_id' => $this->role_id,
        ]);

        session()->flash('success', 'User created successfully.');

        return redirect()->route('users.index');
    }

    public function render()
    {
        return view('livewire.users.create', [
            'roles' => Role::all()
        ])->layout('layouts.master');
    }
}
