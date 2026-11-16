<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Internal documents are generated in PDTS from Word templates with a QR code
 * in the footer. Every document also gets a random scan code: the QR holds a
 * link with this code, never the reference, so links cannot be guessed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdts_document_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->foreignId('document_type_id')->nullable()
                ->constrained('pdts_document_types', indexName: 'pdts_tpl_type_fk')->nullOnDelete();
            // Null for the built-in ZESCO memorandum, which PDTS draws itself.
            $table->string('storage_path')->nullable();
            $table->string('original_filename')->nullable();
            $table->boolean('is_builtin')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('uploaded_by')->nullable()
                ->constrained('users', indexName: 'pdts_tpl_uploader_fk')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('pdts_documents', function (Blueprint $table) {
            $table->string('scan_token', 64)->nullable()->unique('pdts_documents_scan_token_unique');
            $table->string('origin', 20)->default('external');
            $table->foreignId('template_id')->nullable()
                ->constrained('pdts_document_templates', indexName: 'pdts_documents_template_fk')->nullOnDelete();
            $table->json('template_data')->nullable();
        });

        DB::table('pdts_documents')->whereNull('scan_token')->orderBy('id')->each(function ($document) {
            DB::table('pdts_documents')->where('id', $document->id)->update(['scan_token' => Str::random(40)]);
        });

        $memoType = DB::table('pdts_document_types')
            ->where('name', 'like', 'Memo%')
            ->orderBy('id')
            ->value('id');

        DB::table('pdts_document_templates')->insert([
            'name' => 'ZESCO Memorandum',
            'document_type_id' => $memoType,
            'is_builtin' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('pdts_documents', function (Blueprint $table) {
            $table->dropForeign('pdts_documents_template_fk');
            $table->dropUnique('pdts_documents_scan_token_unique');
            $table->dropColumn(['scan_token', 'origin', 'template_id', 'template_data']);
        });

        Schema::dropIfExists('pdts_document_templates');
    }
};
