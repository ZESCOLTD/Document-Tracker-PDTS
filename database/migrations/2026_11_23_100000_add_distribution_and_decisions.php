<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distribute and decide, for internal documents:
 *  - who a document is for in PDTS (the TO person), whether it needs a
 *    decision from them, and who gets a copy for information;
 *  - a document can be a reply to another;
 *  - the TO person's decision, signed, with a decision slip kept as a PDF.
 *
 * None of this moves the paper or changes custody.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdts_documents', function (Blueprint $table) {
            $table->foreignId('recipient_user_id')->nullable()->after('signatory_id')->constrained('users')->nullOnDelete();
            $table->boolean('needs_decision')->default(false)->after('recipient_user_id');
            $table->json('cc_user_ids')->nullable()->after('needs_decision');
            $table->foreignId('reply_to_id')->nullable()->after('revision_of_id')->constrained('pdts_documents')->nullOnDelete();
            $table->index(['recipient_user_id', 'needs_decision']);
        });

        Schema::create('pdts_document_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pdts_document_id')->constrained('pdts_documents')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            // approved, approved_with_conditions, not_approved, returned, noted
            $table->string('decision', 30);
            $table->text('minute')->nullable();
            $table->text('conditions')->nullable();
            $table->foreignId('signature_image_id')->nullable()->constrained('user_signature_images')->nullOnDelete();
            $table->foreignId('attachment_id')->nullable()->constrained('pdts_document_attachments')->nullOnDelete();
            $table->string('file_sha256', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->unique('pdts_document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdts_document_decisions');

        Schema::table('pdts_documents', function (Blueprint $table) {
            $table->dropIndex(['recipient_user_id', 'needs_decision']);
            $table->dropConstrainedForeignId('reply_to_id');
            $table->dropColumn(['needs_decision', 'cc_user_ids']);
            $table->dropConstrainedForeignId('recipient_user_id');
        });
    }
};
