<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Signatures are verified by ICT before they can be used.
 *
 * Each uploaded image has a status: pending (waiting for ICT), active (in
 * use), rejected, suspended, withdrawn (replaced by a newer upload before it
 * was verified) or retired (replaced after being in use). users.signature_path
 * points only to an approved image, so an unverified one can never sign.
 *
 * Signatures uploaded before this change go back to pending, to be verified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_signature_images', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->after('height');
            $table->foreignId('reviewed_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->string('review_note', 500)->nullable()->after('reviewed_at');
            $table->index(['status', 'uploaded_at']);
        });

        Schema::table('pdts_document_signatures', function (Blueprint $table) {
            // The exact image used, and so who verified it.
            $table->foreignId('signature_image_id')->nullable()->after('attachment_id')->constrained('user_signature_images')->nullOnDelete();
        });

        // Images already replaced stay on record as retired; current ones are verified afresh.
        DB::table('user_signature_images')->whereNotNull('retired_at')->update(['status' => 'retired']);
        DB::table('user_signature_images')->whereNull('retired_at')->update(['status' => 'pending']);
        DB::table('users')->whereNotNull('signature_path')->update(['signature_path' => null]);
    }

    public function down(): void
    {
        Schema::table('pdts_document_signatures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('signature_image_id');
        });

        Schema::table('user_signature_images', function (Blueprint $table) {
            $table->dropIndex(['status', 'uploaded_at']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['status', 'reviewed_at', 'review_note']);
        });
    }
};
