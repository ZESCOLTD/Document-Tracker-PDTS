<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revising a signed document was removed: a correction is a new document.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdts_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revision_of_id');
        });
    }

    public function down(): void
    {
        Schema::table('pdts_documents', function (Blueprint $table) {
            $table->foreignId('revision_of_id')->nullable()->after('signature_note')->constrained('pdts_documents')->nullOnDelete();
        });
    }
};
