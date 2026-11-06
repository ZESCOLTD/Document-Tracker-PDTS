<?php

namespace App\Livewire\Users;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;

class UserShow extends Component
{
    public User $user;

    public $name, $email, $role_id, $mobile_no, $man_no, $password;

    public function mount(User $user)
    {
        $this->user = $user;

        $this->name = $user->name;
        $this->email = $user->email;
        $this->role_id = $user->role_id;
        $this->mobile_no = $user->mobile_no;
        $this->man_no = $user->man_no;
    }

    public function update()
    {
        $this->validate([
            'name' => 'required',
            'email' => 'nullable|email|unique:users,email,' . $this->user->id,
            'role_id' => 'required',
        ]);

        $this->user->update([
            'name' => $this->name,
            'email' => $this->email,
            'mobile_no' => $this->mobile_no,
            'man_no' => $this->man_no,
            'role_id' => $this->role_id,
        ]);

        if ($this->password) {
            $this->user->update([
                'password' => Hash::make($this->password)
            ]);
        }

        session()->flash('success', 'User updated successfully.');

        return redirect()->route('users.index');
    }

    public function render()
    {
        return view('livewire.users.show', [
            'roles' => Role::all()
        ])->layout('layouts.master');
    }
}
