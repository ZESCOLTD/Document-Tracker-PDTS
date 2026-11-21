<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Templates now come only from ZESCO's own Word files, uploaded in
 * Configurations. The memorandum PDTS used to draw itself is removed.
 * Documents made from it keep their details; they no longer offer a Word
 * download (template_id becomes null through the foreign key).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('pdts_documents')
            ->whereIn('template_id', DB::table('pdts_document_templates')->where('is_builtin', true)->select('id'))
            ->update(['template_id' => null]);

        DB::table('pdts_document_templates')->where('is_builtin', true)->delete();

        Schema::table('pdts_document_templates', function (Blueprint $table) {
            $table->dropColumn('is_builtin');
        });
    }

    public function down(): void
    {
        Schema::table('pdts_document_templates', function (Blueprint $table) {
            $table->boolean('is_builtin')->default(false)->after('original_filename');
        });
    }
};
