<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class SuperAdministratorSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $role = Role::firstOrCreate([
            'name' => 'Super Administrator',
            'guard_name' => 'web',
        ]);

        $role->syncPermissions(
            \Spatie\Permission\Models\Permission::all()
        );

        /*
        |--------------------------------------------------------------------------
        | Assign System Administrator
        |--------------------------------------------------------------------------
        |
        | Replace 1 with your actual user ID.
        |
        */

        $user = User::find(1);

        if (!$user) {
            $this->command->error('User with ID 1 was not found.');
            return;
        }

        $user->assignRole($role);

        $this->command->info(
            "System Administrator role assigned to {$user->name}."
        );
    }
}
