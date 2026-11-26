<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Electronic signatures: each employee keeps an image of their handwritten
 * signature and a separate signature PIN (stored hashed) on their own
 * account, and every signing is recorded with a fingerprint of the signed
 * file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('signature_path')->nullable();
            $table->string('signature_pin')->nullable();
            $table->timestamp('signature_updated_at')->nullable();
            $table->timestamp('signature_pin_updated_at')->nullable();
        });

        Schema::create('pdts_document_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pdts_document_id')->constrained('pdts_documents')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('attachment_id')->nullable()->constrained('pdts_document_attachments')->nullOnDelete();
            $table->timestamp('signed_at');
            // SHA-256 of the signed PDF, to show later whether it has changed.
            $table->string('file_sha256', 64);
            $table->string('signature_image_sha256', 64);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->unique('pdts_document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdts_document_signatures');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['signature_path', 'signature_pin', 'signature_updated_at', 'signature_pin_updated_at']);
        });
    }
};
