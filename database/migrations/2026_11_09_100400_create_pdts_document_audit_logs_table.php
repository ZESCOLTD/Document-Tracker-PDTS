<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdts_document_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pdts_document_id')->constrained('pdts_documents')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 64);
            $table->json('details')->nullable();
            $table->timestamp('occurred_at');

            $table->index(['pdts_document_id', 'occurred_at'], 'pdts_document_audit_document_occurred_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdts_document_audit_logs');
    }
};