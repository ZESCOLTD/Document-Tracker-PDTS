<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdts_document_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pdts_document_id')->constrained('pdts_documents')->restrictOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('storage_path', 1024);
            $table->string('original_filename', 255);
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('file_size');
            $table->boolean('is_primary')->default(true);
            $table->timestamps();

            $table->index(['pdts_document_id', 'is_primary'], 'pdts_document_attachments_primary_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdts_document_attachments');
    }
};