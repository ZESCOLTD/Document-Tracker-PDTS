<?php

use App\Models\PdtsDocumentTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Word template is offered to authors only when documents from it can be
 * signed in PDTS (it has a signature spot) or when it is marked as not
 * signed (forms, notices, cover sheets), which are issued in PDTS directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdts_document_templates', function (Blueprint $table) {
            $table->boolean('is_unsigned')->default(false)->after('is_active');
        });

        // Word templates already offered without a signature spot wait until
        // the line is marked or they are marked as not signed.
        PdtsDocumentTemplate::query()->where('is_active', true)->where('kind', 'docx')->get()
            ->each(function (PdtsDocumentTemplate $template) {
                if ($template->signingBlocker() !== null) {
                    $template->forceFill(['is_active' => false])->saveQuietly();
                }
            });
    }

    public function down(): void
    {
        Schema::table('pdts_document_templates', function (Blueprint $table) {
            $table->dropColumn('is_unsigned');
        });
    }
};
