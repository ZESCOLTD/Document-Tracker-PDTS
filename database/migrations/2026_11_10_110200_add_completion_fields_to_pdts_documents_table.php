<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdts_documents', function (Blueprint $table) {
            $table->foreignId('completed_by')->nullable()
                ->constrained('users', indexName: 'pdts_documents_completed_by_fk')
                ->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->text('completion_comments')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pdts_documents', function (Blueprint $table) {
            $table->dropForeign('pdts_documents_completed_by_fk');
            $table->dropColumn(['completed_by', 'completed_at', 'completion_comments']);
        });
    }
};