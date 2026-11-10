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
            'documents.complete',
            'documents.cancel',
            'ai.ocr.use',
                'delegations.manage',
            'reports.view',
            'reports.export',
            'audit.view',
            'users.view',
            'users.manage',
            'workflow.view',
            'departments.manage',
            'offices.manage',
            'employee_assignments.manage',
            'document_types.manage',
            'workflows.manage',
            'configuration.view',
            'configuration.manage',
            'configuration_workflow_definitions.view',
            'settings.manage',
            'admin.email_log.view',
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
            'Manager' => [...$employeePermissions, 'documents.complete'],
            'Director / Final Recipient' => [...$employeePermissions, 'documents.complete'],
            'Management / Auditor' => [
                'dashboard.view',
                'documents.view',
                'reports.view',
                'reports.export',
                'audit.view',
            ],
            'System Administrator' => [...$permissions],
        ];

        foreach ($roles as $name => $rolePermissions) {
            $role = Role::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);

            $role->syncPermissions($rolePermissions);
        }
    }
}
