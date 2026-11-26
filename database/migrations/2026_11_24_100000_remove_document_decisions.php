<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decisions on internal documents (approve, approve with conditions, not
 * approve, return, noted, with a signed decision slip) are taken out for now.
 * Who a document is for, copies and replies stay.
 *
 * The approval module is kept on the local branch future/approvals; bringing
 * it back means rolling this migration back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('pdts_document_decisions');

        Schema::table('pdts_documents', function (Blueprint $table) {
            // The recipient's foreign key keeps an index of its own before the shared one goes.
            $table->index('recipient_user_id');
            $table->dropIndex(['recipient_user_id', 'needs_decision']);
            $table->dropColumn('needs_decision');
        });
    }

    public function down(): void
    {
        Schema::table('pdts_documents', function (Blueprint $table) {
            $table->boolean('needs_decision')->default(false)->after('recipient_user_id');
            $table->index(['recipient_user_id', 'needs_decision']);
            $table->dropIndex(['recipient_user_id']);
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
};
