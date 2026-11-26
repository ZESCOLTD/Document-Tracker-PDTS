<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Making templates easy to add:
 *  - field_settings: how each field appears on the New Internal Document form
 *    (label, type, required, auto-fill, help, hidden);
 *  - manual_marks: lines the administrator marked on the preview as a field,
 *    a writing space or the signature spot, for what PDTS did not find itself;
 *  - versions: replacing the file of a template documents were made from
 *    makes a new version; the old one stays for the documents made from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdts_document_templates', function (Blueprint $table) {
            $table->json('field_settings')->nullable()->after('field_labels');
            $table->json('manual_marks')->nullable()->after('field_settings');
            $table->unsignedInteger('version')->default(1)->after('name');
            $table->foreignId('previous_version_id')->nullable()->after('version')->constrained('pdts_document_templates')->nullOnDelete();
            $table->timestamp('superseded_at')->nullable()->after('configured_at');
        });
    }

    public function down(): void
    {
        Schema::table('pdts_document_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('previous_version_id');
            $table->dropColumn(['field_settings', 'manual_marks', 'version', 'superseded_at']);
        });
    }
};
