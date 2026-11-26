<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Templates built in PDTS (the Template Builder) keep their design, from
 * which PDTS writes the Word template. Uploaded templates have none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdts_document_templates', function (Blueprint $table) {
            $table->string('source', 10)->default('upload')->after('kind');
            $table->json('design')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('pdts_document_templates', function (Blueprint $table) {
            $table->dropColumn(['source', 'design']);
        });
    }
};
