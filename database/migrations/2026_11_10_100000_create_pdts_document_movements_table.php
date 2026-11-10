<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdts_document_movements', function (Blueprint $table) {
            $table->id();

            // Explicit short names: Oracle 11g limits identifiers to 30 characters.
            $table->foreignId('pdts_document_id')
                ->constrained('pdts_documents', indexName: 'pdts_mov_document_fk')
                ->restrictOnDelete();
            $table->foreignId('sender_id')
                ->constrained('users', indexName: 'pdts_mov_sender_fk');
            $table->foreignId('recipient_id')
                ->constrained('users', indexName: 'pdts_mov_recipient_fk');

            // Saved at forwarding time, so the tracker stays accurate after someone changes office.
            $table->string('sender_office')->nullable();
            $table->string('recipient_office')->nullable();

            $table->string('status', 20)->default('awaiting_receipt');
            $table->timestamp('forwarded_at')->nullable();
            $table->string('remarks', 1000)->nullable();

            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')
                ->nullable()
                ->constrained('users', indexName: 'pdts_mov_ack_by_fk');

            // Set when a handover is declined or cancelled.
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')
                ->nullable()
                ->constrained('users', indexName: 'pdts_mov_closed_by_fk');
            $table->string('close_reason', 1000)->nullable();

            // Equals pdts_document_id while the handover is pending and is null afterwards.
            // The unique index lets the database reject a second pending handover on
            // MySQL, Oracle and SQLite, none of which share a partial index syntax.
            $table->unsignedBigInteger('open_document_id')->nullable();
            $table->unique('open_document_id', 'pdts_mov_open_document_uq');

            $table->timestamps();

            $table->index(['pdts_document_id', 'forwarded_at'], 'pdts_mov_doc_forwarded_idx');
            $table->index(['recipient_id', 'status'], 'pdts_mov_recipient_status_idx');
            $table->index(['sender_id', 'status'], 'pdts_mov_sender_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdts_document_movements');
    }
};
