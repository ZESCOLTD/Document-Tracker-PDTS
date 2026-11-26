<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prepare on behalf of: a secretary prepares an internal document and the
 * officer it is for (the signatory) reviews and signs it.
 *
 *  - Each document records its signatory separately from who prepared it
 *    (registered_by), and where the signing stands.
 *  - Delegations say what they allow: receiving documents (as before) and/or
 *    preparing documents. Existing delegations keep only receiving.
 *  - Every saved version of a draft is kept.
 *  - A document can be a revision of an earlier, signed one.
 *
 * None of this touches custody or movements: it all happens while the
 * document is a draft.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdts_documents', function (Blueprint $table) {
            $table->foreignId('signatory_id')->nullable()->after('registered_by')->constrained('users')->nullOnDelete();
            // null (prepared and signed by the same person), with_preparer, awaiting_signature, returned, signed
            $table->string('signature_status', 30)->nullable()->after('signatory_id');
            $table->text('signature_note')->nullable()->after('signature_status');
            $table->foreignId('revision_of_id')->nullable()->after('signature_note')->constrained('pdts_documents')->nullOnDelete();
            $table->index(['signatory_id', 'signature_status']);
        });

        Schema::table('pdts_user_delegations', function (Blueprint $table) {
            $table->boolean('can_receive')->default(true)->after('delegate_user_id');
            $table->boolean('can_prepare')->default(false)->after('can_receive');
        });
        DB::table('pdts_user_delegations')->update(['can_receive' => true, 'can_prepare' => false]);

        Schema::create('pdts_document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pdts_document_id')->constrained('pdts_documents')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 30); // created, edited, corrected
            $table->json('data');
            $table->json('changed_fields')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['pdts_document_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdts_document_versions');

        Schema::table('pdts_user_delegations', function (Blueprint $table) {
            $table->dropColumn(['can_receive', 'can_prepare']);
        });

        Schema::table('pdts_documents', function (Blueprint $table) {
            $table->dropIndex(['signatory_id', 'signature_status']);
            $table->dropConstrainedForeignId('revision_of_id');
            $table->dropColumn(['signature_note', 'signature_status']);
            $table->dropConstrainedForeignId('signatory_id');
        });
    }
};
