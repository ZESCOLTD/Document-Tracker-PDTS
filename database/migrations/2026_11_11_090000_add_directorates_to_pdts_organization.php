<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * The organisation is Directorate → Department → Office. Departments now sit
 * under a directorate instead of under a parent department.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdts_directorates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->nullable()->unique();
            $table->string('name', 191)->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::table('pdts_departments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_department_id');
        });

        Schema::table('pdts_departments', function (Blueprint $table) {
            // Nullable so existing departments survive; the form requires it.
            $table->foreignId('directorate_id')->nullable()->after('name')
                ->constrained('pdts_directorates')->restrictOnDelete();
        });

        Schema::table('pdts_documents', function (Blueprint $table) {
            $table->string('originating_directorate', 191)->nullable()->after('document_type_id');
        });

        $this->grantDirectoratePermission();
    }

    /**
     * Whoever manages departments today also manages directorates.
     */
    private function grantDirectoratePermission(): void
    {
        $tables = config('permission.table_names');

        if (! Schema::hasTable($tables['permissions'])) {
            return;
        }

        $permission = DB::table($tables['permissions'])
            ->where(['name' => 'directorates.manage', 'guard_name' => 'web'])
            ->value('id');

        $permission ??= DB::table($tables['permissions'])->insertGetId([
            'name' => 'directorates.manage',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $departmentPermission = DB::table($tables['permissions'])
            ->where(['name' => 'departments.manage', 'guard_name' => 'web'])
            ->value('id');

        if ($departmentPermission) {
            $roleIds = DB::table($tables['role_has_permissions'])
                ->where('permission_id', $departmentPermission)
                ->pluck('role_id');

            foreach ($roleIds as $roleId) {
                DB::table($tables['role_has_permissions'])->insertOrIgnore([
                    'permission_id' => $permission,
                    'role_id' => $roleId,
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // The directorates.manage permission is left in place; it is harmless without the page.
        Schema::table('pdts_documents', function (Blueprint $table) {
            $table->dropColumn('originating_directorate');
        });

        Schema::table('pdts_departments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('directorate_id');
        });

        Schema::table('pdts_departments', function (Blueprint $table) {
            $table->foreignId('parent_department_id')->nullable()->after('name')
                ->constrained('pdts_departments')->restrictOnDelete();
        });

        Schema::dropIfExists('pdts_directorates');
    }
};
