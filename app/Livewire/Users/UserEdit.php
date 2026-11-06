<?php

namespace App\Livewire\Users;

use App\Models\User;
use App\Models\Role;
use Livewire\Component;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UserEdit extends Component
{
    public User $user;

    // Standard Fields
    public $name, $email, $role_id, $mobile_no, $man_no, $password;

    // 🔥 Added Missing PHRIS/Organization Fields
    public $directorate, $division, $location, $section, $job_title, $grade, $station;

    public function mount(User $user)
    {
        $this->user = $user;

        // Populate Standard Fields
        $this->name = $user->name;
        $this->email = $user->email;
        $this->role_id = $user->role_id;
        $this->mobile_no = $user->mobile_no;
        $this->man_no = $user->man_no;

        // 🔥 Populate Organization Fields from the Database
        $this->directorate = $user->directorate;
        $this->division = $user->division;
        $this->location = $user->location;
        $this->section = $user->functional_section; // matches your UserIndex save key
        $this->job_title = $user->job_title;
        $this->grade = $user->grade;
        $this->station = $user->station;
    }

    public function update()
    {
        $rules = [
            'name'      => 'required|string|max:255',
            'email'     => 'required|email|unique:users,email,' . $this->user->id,
            'role_id'   => 'nullable',
            'mobile_no' => 'nullable|string',
            'man_no'    => 'nullable|string',
            // Validation for the organizational fields
            'directorate' => 'nullable|string',
            'job_title'   => 'nullable|string',
        ];

        if (!empty($this->password)) {
            $rules['password'] = ['required', Password::defaults()];
        }

        $this->validate($rules);

        $data = [
            'name'               => $this->name,
            'email'              => $this->email,
            'mobile_no'          => $this->mobile_no,
            'man_no'             => $this->man_no,
            'role_id'            => $this->role_id,
            // 🔥 Include the organizational data in the update array
            'directorate'        => $this->directorate,
            'division'           => $this->division,
            'location'           => $this->location,
            'functional_section' => $this->section,
            'job_title'          => $this->job_title,
            'grade'              => $this->grade,
            'station'            => $this->station,
        ];

        if (!empty($this->password)) {
            $data['password'] = Hash::make($this->password);
        }

        $this->user->update($data);

        session()->flash('success', 'User updated successfully.');

        return redirect()->route('users.index');
    }

    public function render()
    {
        return view('livewire.users.edit', [
            'roles' => Role::all()
        ])->layout('layouts.master');
    }
}
