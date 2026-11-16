<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every SMS PDTS sends, for checking delivery and cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdts_sms_logs', function (Blueprint $table) {
            $table->id();
            $table->string('to', 20);
            $table->text('message');
            $table->string('purpose', 40);
            $table->foreignId('pdts_document_id')->nullable()
                ->constrained('pdts_documents', indexName: 'pdts_sms_document_fk')->nullOnDelete();
            $table->foreignId('user_id')->nullable()
                ->constrained('users', indexName: 'pdts_sms_user_fk')->nullOnDelete();
            $table->string('driver', 20);
            $table->string('status', 20);
            $table->string('error')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['to', 'created_at'], 'pdts_sms_to_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdts_sms_logs');
    }
};
