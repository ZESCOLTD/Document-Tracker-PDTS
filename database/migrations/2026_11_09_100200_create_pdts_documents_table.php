<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdts_documents', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number', 32)->unique();
            $table->string('title', 255);
            $table->string('subject', 255);
            $table->text('description')->nullable();
            $table->foreignId('document_type_id')->constrained('pdts_document_types')->restrictOnDelete();
            $table->string('originating_department', 191);
            $table->string('originating_office', 191);
            $table->foreignId('registered_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('current_custodian_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 32)->default('registered')->index();
            $table->timestamp('registered_at');
            $table->timestamps();

            $table->index(['document_type_id', 'registered_at'], 'pdts_documents_type_registered_index');
            $table->index(['originating_department', 'registered_at'], 'pdts_documents_department_registered_index');
            $table->index(['current_custodian_id', 'status'], 'pdts_documents_custodian_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdts_documents');
    }
};