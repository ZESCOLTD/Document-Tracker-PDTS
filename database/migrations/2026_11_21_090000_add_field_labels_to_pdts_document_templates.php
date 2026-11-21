<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fill-in labels exactly as written in the template ("Appendix A",
 * "Your Ref"), so the New Internal Document form uses the document's own words.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdts_document_templates', function (Blueprint $table) {
            $table->json('field_labels')->nullable()->after('placeholders');
        });
    }

    public function down(): void
    {
        Schema::table('pdts_document_templates', function (Blueprint $table) {
            $table->dropColumn('field_labels');
        });
    }
};
