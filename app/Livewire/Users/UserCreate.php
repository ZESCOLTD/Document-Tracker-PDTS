<?php

namespace App\Livewire\Users;

use App\Actions\Users\ChangeUserRole;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Illuminate\Support\Facades\Hash;

class UserCreate extends Component
{
    public $name, $email, $password, $mobile_no, $man_no;

    // Spatie role name.
    public string $role = '';

    protected function rules()
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:users,email',
            'password' => 'required|min:6',
            'role' => ['required', ...array_slice(ChangeUserRole::rule(), 1)],
            'mobile_no' => 'nullable',
            'man_no' => 'nullable|unique:users,man_no',
        ];
    }

    public function save()
    {
        $this->validate();

        DB::transaction(fn () => tap(User::create([
            'name' => $this->name,
            'email' => $this->email,
            'mobile_no' => $this->mobile_no,
            'man_no' => $this->man_no,
            'password' => Hash::make($this->password),
        ]), fn (User $user) => app(ChangeUserRole::class)($user, $this->role, Auth::user())));

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
