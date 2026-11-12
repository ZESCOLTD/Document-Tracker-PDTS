<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An office belongs either to a directorate (such as the Director's office,
 * which heads the directorate) or to a department (Senior Manager downwards),
 * never both. One office per directorate or department can be marked as its head.
 * A document from a directorate-level office has no originating department.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdts_offices', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
        });

        Schema::table('pdts_offices', function (Blueprint $table) {
            $table->unsignedBigInteger('department_id')->nullable()->change();
            $table->foreign('department_id')->references('id')->on('pdts_departments')->restrictOnDelete();

            $table->foreignId('directorate_id')->nullable()->after('department_id')
                ->constrained('pdts_directorates')->restrictOnDelete();
            $table->boolean('is_head')->default(false)->after('parent_office_id');

            $table->unique(['directorate_id', 'name'], 'pdts_offices_directorate_name_unique');
        });

        Schema::table('pdts_documents', function (Blueprint $table) {
            $table->string('originating_department', 191)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('pdts_offices', function (Blueprint $table) {
            $table->dropUnique('pdts_offices_directorate_name_unique');
            $table->dropConstrainedForeignId('directorate_id');
            $table->dropColumn('is_head');
        });

        // Directorate-level offices have no department and cannot survive the rollback.
        \Illuminate\Support\Facades\DB::table('pdts_offices')->whereNull('department_id')->delete();

        Schema::table('pdts_offices', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
        });

        Schema::table('pdts_offices', function (Blueprint $table) {
            $table->unsignedBigInteger('department_id')->nullable(false)->change();
            $table->foreign('department_id')->references('id')->on('pdts_departments')->restrictOnDelete();
        });
    }
};
