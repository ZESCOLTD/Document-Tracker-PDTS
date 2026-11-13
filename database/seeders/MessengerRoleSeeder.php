<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class MessengerRoleSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            foreach (['dashboard.view', 'deliveries.view'] as $name) {
                Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            }

            $role = Role::firstOrCreate(['name' => 'Messenger', 'guard_name' => 'web']);
            $role->givePermissionTo(['dashboard.view', 'deliveries.view']);

            foreach (Role::where('guard_name', 'web')->whereIn('name', ['System Administrator', 'Super Administrator'])->get() as $administrator) {
                $administrator->givePermissionTo('deliveries.view');
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
