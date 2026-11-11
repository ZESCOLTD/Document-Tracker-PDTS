<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Removes the approval and completion model. Documents are tracked by custody only,
 * so completed and cancelled documents return to the received state with their
 * current custodian intact, and the retired permissions are deleted.
 */
return new class extends Migration
{
    private const RETIRED_PERMISSIONS = [
        'documents.complete',
        'workflow.view',
        'workflows.manage',
        'configuration_workflow_definitions.view',
    ];

    public function up(): void
    {
        DB::table('pdts_documents')
            ->whereIn('status', ['completed', 'cancelled'])
            ->update(['status' => 'received']);

        $tables = config('permission.table_names');

        $permissionIds = DB::table($tables['permissions'])
            ->whereIn('name', self::RETIRED_PERMISSIONS)
            ->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            DB::table($tables['role_has_permissions'])
                ->whereIn('permission_id', $permissionIds)
                ->delete();

            DB::table($tables['model_has_permissions'])
                ->whereIn('permission_id', $permissionIds)
                ->delete();

            DB::table($tables['permissions'])
                ->whereIn('id', $permissionIds)
                ->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Not reversible: retired permissions and status values are not restored.
    }
};
