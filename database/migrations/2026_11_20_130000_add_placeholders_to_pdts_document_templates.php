<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Templates are ZESCO's own Word or PDF files. PDTS keeps the original as it
 * was uploaded, the administrator places the QR code on a preview, and PDTS
 * keeps that placement and the prepared file it makes from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdts_document_templates', function (Blueprint $table) {
            $table->string('kind', 10)->default('docx')->after('name'); // docx or pdf
            $table->text('description')->nullable()->after('kind');
            // The file exactly as uploaded. storage_path holds the prepared file.
            $table->string('original_path')->nullable()->after('storage_path');
            // The ${placeholders} in the prepared Word file, so the New
            // Internal Document form shows only the fields it uses.
            $table->json('placeholders')->nullable()->after('original_filename');
            // Where the QR code goes: every page, the first page, or a page
            // number (PDF), and its top-left corner and size in millimetres.
            $table->string('qr_page', 10)->nullable()->after('placeholders');
            $table->decimal('qr_x_mm', 6, 1)->nullable()->after('qr_page');
            $table->decimal('qr_y_mm', 6, 1)->nullable()->after('qr_x_mm');
            $table->decimal('qr_size_mm', 5, 1)->nullable()->after('qr_y_mm');
            $table->unsignedSmallInteger('page_count')->nullable()->after('qr_size_mm');
            // Null until the QR code has been placed and the template saved.
            $table->timestamp('configured_at')->nullable()->after('page_count');
        });

        // Templates uploaded before this change were ready to use as they were.
        DB::table('pdts_document_templates')
            ->whereNotNull('storage_path')
            ->update([
                'original_path' => DB::raw('storage_path'),
                'configured_at' => DB::raw('updated_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('pdts_document_templates', function (Blueprint $table) {
            $table->dropColumn([
                'kind', 'description', 'original_path', 'placeholders',
                'qr_page', 'qr_x_mm', 'qr_y_mm', 'qr_size_mm', 'page_count', 'configured_at',
            ]);
        });
    }
};
