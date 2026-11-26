<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PdtsAccessSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'dashboard.view',
            'documents.view',
            'documents.register',
            'documents.update',
            'documents.forward',
            'documents.acknowledge',
            'documents.cancel',
            'ai.ocr.use',
            'delegations.manage',
            'reports.view',
            'reports.export',
            'audit.view',
            'users.view',
            'users.manage',
            'directorates.manage',
            'departments.manage',
            'offices.manage',
            'employee_assignments.manage',
            'document_types.manage',
            'configuration.view',
            'configuration.manage',
            'settings.manage',
            'admin.email_log.view',
            // Let secretaries/assistants prepare documents for one to sign. Directors, Senior Managers and
            // Managers have this by their grade (config/pdts.php); this permission is only an exception,
            // given to a named person on purpose, never through a role.
            'documents.authorise_preparers',
            // ICT: verify employees' signatures before they can be used to sign.
            'signatures.verify',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        $employeePermissions = [
            'dashboard.view',
            'documents.view',
            'documents.register',
            'documents.update',
            'documents.forward',
            'documents.acknowledge',
            'ai.ocr.use',
        ];

        $roles = [
            'Originating Employee' => $employeePermissions,
            'Supervisor' => $employeePermissions,
            'Manager' => $employeePermissions,
            'Director / Final Recipient' => $employeePermissions,
            'Management / Auditor' => [
                'dashboard.view',
                'documents.view',
                'reports.view',
                'reports.export',
                'audit.view',
            ],
            // Everything except the preparer exception, which goes by grade or to a named person.
            'System Administrator' => array_values(array_diff($permissions, ['documents.authorise_preparers'])),
        ];

        foreach ($roles as $name => $rolePermissions) {
            $role = Role::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);

            $role->syncPermissions($rolePermissions);
        }

        $this->call(MessengerRoleSeeder::class);
    }
}
