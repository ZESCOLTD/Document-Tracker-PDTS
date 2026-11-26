<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Closing a document's life:
 *  - when it was completed and where it is filed (file number or location);
 *  - cancellation by an administrator, with the reason;
 *  - how long each document type is kept, for the archive's review list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdts_documents', function (Blueprint $table) {
            // completed_at and completed_by exist already (2026_11_10_110200).
            $table->string('file_reference', 150)->nullable()->after('completed_at');
            $table->timestamp('cancelled_at')->nullable()->after('file_reference');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 1000)->nullable()->after('cancelled_by');
            $table->index(['status', 'completed_at']);
        });

        Schema::table('pdts_document_types', function (Blueprint $table) {
            $table->unsignedSmallInteger('retention_years')->nullable()->after('name');
        });

        // Documents completed before this change: their completion date from the activity record.
        foreach (DB::table('pdts_document_audit_logs')->where('action', 'document_completed')->orderBy('occurred_at')->get(['pdts_document_id', 'occurred_at']) as $log) {
            DB::table('pdts_documents')->where('id', $log->pdts_document_id)->whereNull('completed_at')->update(['completed_at' => $log->occurred_at]);
        }
    }

    public function down(): void
    {
        Schema::table('pdts_document_types', function (Blueprint $table) {
            $table->dropColumn('retention_years');
        });

        Schema::table('pdts_documents', function (Blueprint $table) {
            $table->dropIndex(['status', 'completed_at']);
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['file_reference', 'cancelled_at', 'cancel_reason']);
        });
    }
};
